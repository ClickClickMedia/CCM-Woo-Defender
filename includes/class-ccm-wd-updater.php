<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Updater {
    private string $file;
    private string $plugin;
    private string $basename;
    private bool $active;
    private string $username;
    private string $repository;
    private string $authorize_token;
    private ?object $github_response = null;

    public function __construct( string $file ) {
        $this->ensure_plugin_functions_loaded();

        $this->file      = $file;
        $this->plugin    = plugin_basename( $file );
        $this->basename  = dirname( $this->plugin );
        $this->active    = is_plugin_active( $this->plugin );
        $this->username  = 'ClickClickMedia';
        $this->repository = 'CCM-Woo-Defender';
        $this->authorize_token = defined( 'CCM_WD_GITHUB_TOKEN' ) ? (string) CCM_WD_GITHUB_TOKEN : '';

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ), 5, 1 );
        add_filter( 'site_transient_update_plugins', array( $this, 'check_for_update' ), 10, 1 );
        add_filter( 'plugins_api', array( $this, 'plugin_popup' ), 10, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
        add_filter( 'http_request_args', array( $this, 'add_auth_to_request' ), 10, 2 );

        add_action( 'load-plugins.php', array( $this, 'force_update_check_on_plugins_page' ) );
        add_action( 'load-update-core.php', array( $this, 'force_update_check_on_plugins_page' ) );
    }

    public static function initialize( string $plugin_file ): void {
        static $bootstrapped = false;

        if ( $bootstrapped ) {
            return;
        }

        $doing_cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
        $doing_ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();

        if ( ! $doing_cron && ! is_admin() ) {
            return;
        }

        if ( ! $doing_cron && is_admin() && ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        if ( $doing_ajax && ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        if ( ! file_exists( $plugin_file ) ) {
            return;
        }

        $bootstrapped = true;
        new self( $plugin_file );
    }

    public static function maybe_force_refresh_on_admin_init(): void {
        if ( ! is_admin() ) {
            return;
        }

        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        $force_requested = isset( $_GET['force-check'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['force-check'] ) );

        if ( ! $force_requested ) {
            return;
        }

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ccm_wd_github_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_site_transient_update_plugins%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        delete_site_transient( 'update_plugins' );
        wp_clean_plugins_cache( true );
    }

    public function modify_transient( $transient ) {
        if ( empty( $transient ) ) {
            $transient = new stdClass();
        }

        if ( ! isset( $transient->checked ) ) {
            $transient->checked = array();
        }

        if ( empty( $transient->checked[ $this->plugin ] ) ) {
            $plugin_data                         = get_plugin_data( $this->file );
            $transient->checked[ $this->plugin ] = $plugin_data['Version'];
        }

        $this->get_repository_info();

        if ( empty( $this->github_response ) || ! is_object( $this->github_response ) ) {
            return $transient;
        }

        $github_version  = $this->get_github_version();
        $plugin_data     = get_plugin_data( $this->file );
        $current_version = $plugin_data['Version'];

        $obj              = new stdClass();
        $obj->slug        = $this->basename;
        $obj->plugin      = $this->plugin;
        $obj->new_version = $github_version;
        $obj->url         = $this->github_response->html_url ?? '';
        $obj->package     = $this->get_download_url();
        $obj->tested      = $this->get_current_wp_version();

        if ( version_compare( $github_version, $current_version, '>' ) ) {
            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
                $transient->response = array();
            }

            $transient->response[ $this->plugin ] = $obj;
        } else {
            if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
                $transient->no_update = array();
            }

            $transient->no_update[ $this->plugin ] = $obj;
        }

        return $transient;
    }

    public function check_for_update( $transient ) {
        if ( isset( $transient->response[ $this->plugin ] ) ) {
            return $transient;
        }

        $this->get_repository_info();

        if ( empty( $this->github_response ) || ! is_object( $this->github_response ) ) {
            return $transient;
        }

        $github_version  = $this->get_github_version();
        $plugin_data     = get_plugin_data( $this->file );
        $current_version = $plugin_data['Version'];

        if ( ! version_compare( $github_version, $current_version, '>' ) ) {
            return $transient;
        }

        $obj              = new stdClass();
        $obj->slug        = $this->basename;
        $obj->plugin      = $this->plugin;
        $obj->new_version = $github_version;
        $obj->url         = $this->github_response->html_url ?? '';
        $obj->package     = $this->get_download_url();
        $obj->tested      = $this->get_current_wp_version();

        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }

        $transient->response[ $this->plugin ] = $obj;

        return $transient;
    }

    public function plugin_popup( $result, string $action, $args ) {
        if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->basename ) {
            return $result;
        }

        $this->get_repository_info();

        if ( empty( $this->github_response ) || ! is_object( $this->github_response ) ) {
            return $result;
        }

        $plugin_data               = get_plugin_data( $this->file );
        $plugin_info               = new stdClass();
        $plugin_info->name         = $plugin_data['Name'];
        $plugin_info->slug         = $this->basename;
        $plugin_info->version      = $this->get_github_version();
        $plugin_info->author       = $plugin_data['Author'];
        $plugin_info->author_profile = $plugin_data['AuthorURI'];
        $plugin_info->homepage     = ! empty( $plugin_data['PluginURI'] ) ? $plugin_data['PluginURI'] : ( $this->github_response->html_url ?? '' );
        $plugin_info->requires     = ! empty( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '6.0';
        $plugin_info->requires_php = ! empty( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '8.1';
        $plugin_info->tested       = $this->get_current_wp_version();
        $plugin_info->last_updated = isset( $this->github_response->published_at ) ? gmdate( 'Y-m-d', strtotime( (string) $this->github_response->published_at ) ) : gmdate( 'Y-m-d' );
        $plugin_info->sections     = array(
            'description' => $plugin_data['Description'],
            'changelog'   => $this->get_changelog(),
        );
        $plugin_info->download_link = $this->get_download_url();

        return $plugin_info;
    }

    public function after_install( $response, array $hook_extra, array $result ) {
        global $wp_filesystem;

        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin ) {
            return $response;
        }

        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
            global $wp_filesystem;
        }

        if ( ! $wp_filesystem || ! isset( $result['destination'] ) ) {
            return $result;
        }

        $install_directory = plugin_dir_path( $this->file );
        $wp_filesystem->move( $result['destination'], $install_directory );
        $result['destination'] = $install_directory;

        if ( $this->active ) {
            activate_plugin( $this->plugin );
        }

        return $result;
    }

    public function add_auth_to_request( array $args, string $url ): array {
        if ( false === strpos( $url, 'github.com' ) && false === strpos( $url, 'api.github.com' ) ) {
            return $args;
        }

        if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
            $args['headers'] = array();
        }

        if ( ! empty( $this->authorize_token ) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->authorize_token;
        }

        $args['headers']['Accept']               = 'application/vnd.github+json';
        $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';
        if ( empty( $args['headers']['User-Agent'] ) ) {
            $args['headers']['User-Agent'] = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url();
        }

        return $args;
    }

    public function force_update_check_on_plugins_page(): void {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        $force_requested = isset( $_GET['force-check'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['force-check'] ) );
        if ( ! $force_requested ) {
            return;
        }

        delete_transient( 'ccm_wd_github_' . md5( $this->basename ) );
        $this->github_response = null;
        delete_site_transient( 'update_plugins' );
        wp_clean_plugins_cache( true );
    }

    private function get_current_wp_version(): string {
        global $wp_version;
        return (string) $wp_version;
    }

    private function ensure_plugin_functions_loaded(): void {
        if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    private function get_repository_info(): bool {
        if ( ! empty( $this->github_response ) ) {
            return true;
        }

        $transient_key   = 'ccm_wd_github_' . md5( $this->basename );
        $cached_response = get_transient( $transient_key );

        if ( $cached_response && is_object( $cached_response ) ) {
            $this->github_response = $cached_response;
            return true;
        }

        $url      = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
        $response = $this->api_request( $url );

        if ( empty( $response ) || ! is_object( $response ) ) {
            return false;
        }

        $this->github_response = $response;
        set_transient( $transient_key, $response, HOUR_IN_SECONDS );

        return true;
    }

    private function get_github_version(): string {
        if ( empty( $this->github_response ) || empty( $this->github_response->tag_name ) ) {
            return '0.0.0';
        }

        $version = ltrim( (string) $this->github_response->tag_name, 'v' );

        if ( false === strpos( $version, '.' ) ) {
            $version .= '.0';
        }

        return $version;
    }

    private function get_download_url(): string {
        if ( empty( $this->github_response ) || ! is_object( $this->github_response ) ) {
            return '';
        }

        if ( ! empty( $this->github_response->assets ) && is_array( $this->github_response->assets ) ) {
            foreach ( $this->github_response->assets as $asset ) {
                if ( isset( $asset->browser_download_url ) && isset( $asset->name ) && false !== strpos( (string) $asset->name, '.zip' ) ) {
                    return (string) $asset->browser_download_url;
                }
            }
        }

        return isset( $this->github_response->zipball_url ) ? (string) $this->github_response->zipball_url : '';
    }

    private function get_changelog(): string {
        if ( empty( $this->github_response ) || empty( $this->github_response->body ) ) {
            return 'No changelog provided';
        }

        $changelog = (string) $this->github_response->body;
        $changelog = preg_replace( '/\r\n|\r/', "\n", $changelog ) ?? $changelog;
        $changelog = preg_replace( '/###(.*?)\n/', '<h3>$1</h3>', $changelog ) ?? $changelog;
        $changelog = preg_replace( '/##(.*?)\n/', '<h2>$1</h2>', $changelog ) ?? $changelog;
        $changelog = preg_replace( '/\*\*(.*?)\*\*/', '<strong>$1</strong>', $changelog ) ?? $changelog;
        $changelog = preg_replace( '/\*(.*?)\*/', '<em>$1</em>', $changelog ) ?? $changelog;
        $changelog = preg_replace( '/- (.*?)(\n|$)/', '<li>$1</li>', $changelog ) ?? $changelog;
        $changelog = preg_replace( '/((?:<li>.*<\/li>\n?)+)/', '<ul>$1</ul>', $changelog ) ?? $changelog;

        return $changelog;
    }

    private function api_request( string $url ) {
        $headers = array(
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
        );

        if ( ! empty( $this->authorize_token ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->authorize_token;
        }

        $response = wp_remote_get(
            $url,
            array(
                'headers'     => $headers,
                'timeout'     => 20,
                'sslverify'   => true,
                'redirection' => 5,
            )
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $data = json_decode( (string) wp_remote_retrieve_body( $response ) );

        return empty( $data ) ? false : $data;
    }
}
