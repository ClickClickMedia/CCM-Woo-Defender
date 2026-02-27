<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Admin {
    private CCM_WD_Store $store;
    private CCM_WD_Settings $settings;

    public function __construct( CCM_WD_Store $store, CCM_WD_Settings $settings ) {
        $this->store    = $store;
        $this->settings = $settings;
    }

    public function register_hooks(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_ccm_wd_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_ccm_wd_reset_settings', array( $this, 'handle_reset_settings' ) );
        add_action( 'admin_post_ccm_wd_clear_data', array( $this, 'handle_clear_data' ) );
        add_action( 'wp_ajax_ccm_wd_toggle_advanced', array( $this, 'ajax_toggle_advanced' ) );

        // Add "Settings" link on the Plugins page.
        $plugin_basename = plugin_basename( CCM_WD_FILE );
        add_filter( "plugin_action_links_{$plugin_basename}", array( $this, 'plugin_action_links' ) );
    }

    /**
     * Add a "Settings" link to the plugin row on the Plugins page.
     */
    public function plugin_action_links( array $links ): array {
        $settings_url  = admin_url( 'admin.php?page=ccm-woo-defender' );
        $settings_link = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'ccm-woo-defender' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'CCM Woo Defender', 'ccm-woo-defender' ),
            __( 'CCM Woo Defender', 'ccm-woo-defender' ),
            'manage_woocommerce',
            'ccm-woo-defender',
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue admin CSS and JS on the plugin page only.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( 'woocommerce_page_ccm-woo-defender' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'ccm-wd-admin',
            plugin_dir_url( CCM_WD_FILE ) . 'css/ccm-wd-admin.css',
            array( 'dashicons' ),
            CCM_WD_VERSION
        );

        wp_enqueue_script(
            'ccm-wd-admin',
            plugin_dir_url( CCM_WD_FILE ) . 'js/ccm-wd-admin.js',
            array(),
            CCM_WD_VERSION,
            true
        );

        wp_localize_script( 'ccm-wd-admin', 'ccmWdAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ccm_wd_toggle_advanced' ),
        ) );
    }

    public function handle_save_settings(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'ccm-woo-defender' ) );
        }

        check_admin_referer( 'ccm_wd_save_settings' );

        $input = isset( $_POST['ccm_wd_settings'] ) && is_array( $_POST['ccm_wd_settings'] )
            ? wp_unslash( $_POST['ccm_wd_settings'] )
            : array();

        $this->settings->update( $input );

        wp_safe_redirect( admin_url( 'admin.php?page=ccm-woo-defender&tab=settings&saved=1' ) );
        exit;
    }

    public function handle_reset_settings(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'ccm-woo-defender' ) );
        }

        check_admin_referer( 'ccm_wd_reset_settings' );

        $this->settings->update( $this->settings->defaults() );

        wp_safe_redirect( admin_url( 'admin.php?page=ccm-woo-defender&tab=settings&reset=1' ) );
        exit;
    }

    public function handle_clear_data(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'ccm-woo-defender' ) );
        }

        check_admin_referer( 'ccm_wd_clear_data' );

        $this->store->clear_blocks();
        $this->store->clear_events();

        wp_safe_redirect( admin_url( 'admin.php?page=ccm-woo-defender&tab=overview&cleared=1' ) );
        exit;
    }

    /**
     * AJAX handler: toggle advanced_mode on/off and persist immediately.
     */
    public function ajax_toggle_advanced(): void {
        check_ajax_referer( 'ccm_wd_toggle_advanced' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        }

        $current = $this->settings->get();
        $current['advanced_mode'] = ! empty( $_POST['advanced_mode'] );
        $this->settings->update( $current );

        wp_send_json_success( array( 'advanced_mode' => ! empty( $current['advanced_mode'] ) ) );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $stats        = $this->store->get_stats();
        $settings     = $this->settings->get();
        $selected_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview';

        if ( ! in_array( $selected_tab, array( 'overview', 'history', 'settings' ), true ) ) {
            $selected_tab = 'overview';
        }
        ?>
        <div class="wrap ccm-wd">

            <div class="ccm-wd-header">
                <div class="ccm-wd-header-brand">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ccm-woo-defender' ) ); ?>" class="ccm-wd-header-logo">
                        <img src="<?php echo esc_url( plugin_dir_url( CCM_WD_FILE ) . 'assets/logo.svg' ); ?>" alt="Click Click Media">
                    </a>
                    <h1><?php esc_html_e( 'Woo Defender', 'ccm-woo-defender' ); ?></h1>
                    <span class="ccm-wd-version">v<?php echo esc_html( CCM_WD_VERSION ); ?></span>
                </div>
                <div class="ccm-wd-tabs">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ccm-woo-defender&tab=overview' ) ); ?>" class="ccm-wd-tab <?php echo 'overview' === $selected_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Overview', 'ccm-woo-defender' ); ?></a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ccm-woo-defender&tab=history' ) ); ?>" class="ccm-wd-tab <?php echo 'history' === $selected_tab ? 'active' : ''; ?>"><?php esc_html_e( 'History', 'ccm-woo-defender' ); ?></a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ccm-woo-defender&tab=settings' ) ); ?>" class="ccm-wd-tab <?php echo 'settings' === $selected_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Settings', 'ccm-woo-defender' ); ?></a>
                </div>
            </div>

            <div class="ccm-wd-content">

                <?php if ( isset( $_GET['cleared'] ) ) : ?>
                    <div class="ccm-wd-alert ccm-wd-alert-success"><p><?php esc_html_e( 'Woo Defender events and blocks were cleared.', 'ccm-woo-defender' ); ?></p></div>
                <?php endif; ?>
                <?php if ( isset( $_GET['saved'] ) ) : ?>
                    <div class="ccm-wd-alert ccm-wd-alert-success"><p><?php esc_html_e( 'Settings saved successfully.', 'ccm-woo-defender' ); ?></p></div>
                <?php endif; ?>
                <?php if ( isset( $_GET['reset'] ) ) : ?>
                    <div class="ccm-wd-alert ccm-wd-alert-success"><p><?php esc_html_e( 'Settings reset to defaults.', 'ccm-woo-defender' ); ?></p></div>
                <?php endif; ?>

                <?php if ( 'overview' === $selected_tab ) : ?>
                    <?php $this->render_overview( $stats, $settings ); ?>
                <?php elseif ( 'history' === $selected_tab ) : ?>
                    <?php $this->render_history(); ?>
                <?php else : ?>
                    <?php $this->render_settings( $settings ); ?>
                <?php endif; ?>

            </div>

        </div>
        <?php
    }

    /**
     * @param array<string, int> $stats
     * @param array<string, int|bool|string> $settings
     */
    private function render_overview( array $stats, array $settings ): void {
        $manual_ips = $this->settings->get_manual_blocked_ips();
        ?>

        <!-- Stats Grid -->
        <div class="ccm-wd-stats-grid">
            <div class="ccm-wd-stat-box">
                <span class="ccm-wd-stat-value"><?php echo esc_html( (string) $stats['events_total'] ); ?></span>
                <span class="ccm-wd-stat-label"><?php esc_html_e( 'Checkout Attempts', 'ccm-woo-defender' ); ?></span>
            </div>
            <div class="ccm-wd-stat-box">
                <span class="ccm-wd-stat-value"><?php echo esc_html( (string) $stats['events_blocked'] ); ?></span>
                <span class="ccm-wd-stat-label"><?php esc_html_e( 'Blocked', 'ccm-woo-defender' ); ?></span>
            </div>
            <div class="ccm-wd-stat-box">
                <span class="ccm-wd-stat-value"><?php echo esc_html( (string) $stats['active_blocks'] ); ?></span>
                <span class="ccm-wd-stat-label"><?php esc_html_e( 'Active Blocks', 'ccm-woo-defender' ); ?></span>
            </div>
        </div>

        <!-- Status Details -->
        <div class="ccm-wd-card">
            <h2><?php esc_html_e( 'Status Details', 'ccm-woo-defender' ); ?></h2>
            <table class="ccm-wd-table">
                <tbody>
                <tr>
                    <th><?php esc_html_e( 'Protection', 'ccm-woo-defender' ); ?></th>
                    <td>
                        <?php if ( ! empty( $settings['enabled'] ) ) : ?>
                            <span class="ccm-wd-badge ccm-wd-badge-success"><?php esc_html_e( 'Enabled', 'ccm-woo-defender' ); ?></span>
                        <?php else : ?>
                            <span class="ccm-wd-badge ccm-wd-badge-error"><?php esc_html_e( 'Disabled', 'ccm-woo-defender' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Mode', 'ccm-woo-defender' ); ?></th>
                    <td>
                        <?php if ( ! empty( $settings['advanced_mode'] ) ) : ?>
                            <span class="ccm-wd-badge ccm-wd-badge-warning"><?php esc_html_e( 'Advanced', 'ccm-woo-defender' ); ?></span>
                        <?php else : ?>
                            <span class="ccm-wd-badge ccm-wd-badge-info"><?php esc_html_e( 'Easy', 'ccm-woo-defender' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Preset Profile', 'ccm-woo-defender' ); ?></th>
                    <td><span class="ccm-wd-badge ccm-wd-badge-neutral"><?php echo esc_html( ucfirst( (string) $settings['profile'] ) ); ?></span></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Manual Blocked IPs', 'ccm-woo-defender' ); ?></th>
                    <td>
                        <?php if ( empty( $manual_ips ) ) : ?>
                            <span class="ccm-wd-text-muted"><?php esc_html_e( 'None configured', 'ccm-woo-defender' ); ?></span>
                        <?php else : ?>
                            <strong><?php echo esc_html( (string) count( $manual_ips ) ); ?></strong>
                            <ul class="ccm-wd-ip-list">
                                <?php foreach ( $manual_ips as $ip ) : ?>
                                    <li><?php echo esc_html( $ip ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <!-- How Woo Defender Works -->
        <div class="ccm-wd-card">
            <h2><?php esc_html_e( 'How Woo Defender Works', 'ccm-woo-defender' ); ?></h2>
            <div class="ccm-wd-alert ccm-wd-alert-info">
                <div>
                    <p><strong><?php esc_html_e( 'Simple version:', 'ccm-woo-defender' ); ?></strong></p>
                    <ol>
                        <li><?php esc_html_e( 'At checkout, Defender creates a privacy-safe fingerprint from non-sensitive patterns such as payment method, amount, country, IP/device consistency, and address quality. Raw personal data is not stored.', 'ccm-woo-defender' ); ?></li>
                        <li><?php esc_html_e( 'It compares this attempt with recent checkout history to detect fraud-style behavior, for example: same gateway + same amount with many different identities, same IP with multiple addresses, or repeated attempts after previous blocks.', 'ccm-woo-defender' ); ?></li>
                        <li><?php esc_html_e( 'Each signal adds to a risk score. If the score reaches your configured threshold, checkout is stopped immediately and related fingerprints are temporarily blocked.', 'ccm-woo-defender' ); ?></li>
                        <li><?php esc_html_e( 'Defender also learns from failed and cancelled orders, so detection improves over time for your specific abuse patterns.', 'ccm-woo-defender' ); ?></li>
                    </ol>
                    <p><strong><?php esc_html_e( 'Why this works better than basic rate limiting:', 'ccm-woo-defender' ); ?></strong> <?php esc_html_e( 'Many attackers deliberately spread attempts over time to bypass burst limits. Defender focuses on correlated fraud fingerprints and identity churn, which remain visible even when attempts are sporadic.', 'ccm-woo-defender' ); ?></p>
                </div>
            </div>
            <p class="ccm-wd-text-muted"><?php esc_html_e( 'Tip: Keep protection enabled and tune threshold/weights in Settings to reduce false positives.', 'ccm-woo-defender' ); ?></p>
        </div>

        <!-- Data Management -->
        <div class="ccm-wd-card">
            <h2><?php esc_html_e( 'Data Management', 'ccm-woo-defender' ); ?></h2>
            <p><?php esc_html_e( 'Clear all tracked checkout events and active block tokens. This cannot be undone.', 'ccm-woo-defender' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ccm_wd_clear_data" />
                <?php wp_nonce_field( 'ccm_wd_clear_data' ); ?>
                <button type="submit" class="ccm-wd-button ccm-wd-button-danger" onclick="return confirm('<?php echo esc_js( __( 'Clear all Woo Defender data? This cannot be undone.', 'ccm-woo-defender' ) ); ?>');">
                    <?php esc_html_e( 'Clear Woo Defender Data', 'ccm-woo-defender' ); ?>
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Render the History tab showing a paginated log of checkout events.
     */
    private function render_history(): void {
        $per_page    = 30;
        $total       = $this->store->get_events_count();
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        $current     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

        if ( $current > $total_pages ) {
            $current = $total_pages;
        }

        // Get all events (newest first), then slice for current page.
        $all_events = $this->store->get_history_events();
        $offset     = ( $current - 1 ) * $per_page;
        $events     = array_slice( $all_events, $offset, $per_page );

        $countries = CCM_WD_GeoIP::get_all_countries();
        ?>

        <!-- History Header -->
        <div class="ccm-wd-card">
            <h2><?php esc_html_e( 'Checkout History', 'ccm-woo-defender' ); ?></h2>
            <p class="ccm-wd-text-muted">
                <?php
                printf(
                    /* translators: %d = total event count */
                    esc_html__( 'Showing %1$d of %2$d recorded checkout events (newest first). Events are automatically pruned after 30 days or when exceeding 2,500 entries.', 'ccm-woo-defender' ),
                    count( $events ),
                    $total
                );
                ?>
            </p>

            <?php if ( empty( $events ) ) : ?>
                <div class="ccm-wd-alert ccm-wd-alert-info">
                    <p><?php esc_html_e( 'No checkout events recorded yet. Events will appear here as customers attempt checkout.', 'ccm-woo-defender' ); ?></p>
                </div>
            <?php else : ?>

                <!-- Pagination (top) -->
                <?php if ( $total_pages > 1 ) : ?>
                    <?php $this->render_pagination( $current, $total_pages ); ?>
                <?php endif; ?>

                <div class="ccm-wd-history-table-wrap">
                    <table class="ccm-wd-table ccm-wd-history-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Date / Time', 'ccm-woo-defender' ); ?></th>
                                <th><?php esc_html_e( 'IP Address', 'ccm-woo-defender' ); ?></th>
                                <th><?php esc_html_e( 'Country', 'ccm-woo-defender' ); ?></th>
                                <th><?php esc_html_e( 'Gateway', 'ccm-woo-defender' ); ?></th>
                                <th><?php esc_html_e( 'Total', 'ccm-woo-defender' ); ?></th>
                                <th><?php esc_html_e( 'Score', 'ccm-woo-defender' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'ccm-woo-defender' ); ?></th>
                                <th><?php esc_html_e( 'Reasons', 'ccm-woo-defender' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $events as $event ) : ?>
                                <?php
                                $ts            = (int) ( $event['ts'] ?? 0 );
                                $client_ip     = (string) ( $event['client_ip'] ?? '' );
                                $geoip_country = (string) ( $event['geoip_country'] ?? '' );
                                $billing_country = (string) ( $event['country'] ?? '' );
                                $gateway       = (string) ( $event['gateway'] ?? '' );
                                $total_val     = (string) ( $event['total'] ?? '' );
                                $score         = (int) ( $event['score'] ?? 0 );
                                $blocked       = ! empty( $event['blocked'] );
                                $reasons       = (string) ( $event['reasons'] ?? '' );

                                // Legacy fallback: extract GeoIP country from reasons if not stored as a field.
                                if ( '' === $geoip_country && preg_match( '/geoip_country_(?:block|score):([A-Z]{2})/', $reasons, $geo_m ) ) {
                                    $geoip_country = $geo_m[1];
                                }

                                // Resolve country display: show GeoIP country if available, fall back to billing country.
                                $display_country_code = '' !== $geoip_country ? $geoip_country : $billing_country;
                                $display_country_name = '';
                                if ( '' !== $display_country_code ) {
                                    $display_country_name = $countries[ $display_country_code ] ?? $display_country_code;
                                }

                                // Format the country label with source indicator.
                                $country_label = '';
                                if ( '' !== $geoip_country && isset( $countries[ $geoip_country ] ) ) {
                                    $country_label = $geoip_country . ' – ' . $countries[ $geoip_country ];
                                } elseif ( '' !== $billing_country ) {
                                    $country_label = $billing_country . ( isset( $countries[ $billing_country ] ) ? ' – ' . $countries[ $billing_country ] : '' );
                                }

                                // Format reasons into readable tags.
                                $reason_tags = array_filter( array_map( 'trim', explode( ',', $reasons ) ) );
                                ?>
                                <tr class="<?php echo $blocked ? 'ccm-wd-row-blocked' : 'ccm-wd-row-allowed'; ?>">
                                    <td class="ccm-wd-history-date">
                                        <?php if ( $ts > 0 ) : ?>
                                            <span class="ccm-wd-date-primary"><?php echo esc_html( wp_date( 'M j, Y', $ts ) ); ?></span>
                                            <span class="ccm-wd-date-secondary"><?php echo esc_html( wp_date( 'H:i:s', $ts ) ); ?></span>
                                        <?php else : ?>
                                            <span class="ccm-wd-text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( '' !== $client_ip ) : ?>
                                            <code class="ccm-wd-ip-code"><?php echo esc_html( $client_ip ); ?></code>
                                        <?php else : ?>
                                            <span class="ccm-wd-text-muted"><?php esc_html_e( 'N/A', 'ccm-woo-defender' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( '' !== $country_label ) : ?>
                                            <span class="ccm-wd-country-badge" title="<?php echo esc_attr( $country_label ); ?>">
                                                <?php echo esc_html( $display_country_code ); ?>
                                                <span class="ccm-wd-country-tooltip"><?php echo esc_html( $display_country_name ); ?></span>
                                            </span>
                                            <?php if ( '' !== $geoip_country && '' !== $billing_country && $geoip_country !== $billing_country ) : ?>
                                                <span class="ccm-wd-country-mismatch" title="<?php echo esc_attr( sprintf( __( 'GeoIP: %1$s, Billing: %2$s', 'ccm-woo-defender' ), $geoip_country, $billing_country ) ); ?>">
                                                    <span class="dashicons dashicons-warning"></span>
                                                </span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span class="ccm-wd-text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo '' !== $gateway ? esc_html( $gateway ) : '<span class="ccm-wd-text-muted">&mdash;</span>'; ?></td>
                                    <td>
                                        <?php if ( '' !== $total_val && '0.00' !== $total_val ) : ?>
                                            <?php echo esc_html( '$' . $total_val ); ?>
                                        <?php else : ?>
                                            <span class="ccm-wd-text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="ccm-wd-score <?php echo $score >= 70 ? 'ccm-wd-score-high' : ( $score >= 40 ? 'ccm-wd-score-medium' : 'ccm-wd-score-low' ); ?>">
                                            <?php echo esc_html( (string) $score ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ( $blocked ) : ?>
                                            <span class="ccm-wd-badge ccm-wd-badge-error"><?php esc_html_e( 'Blocked', 'ccm-woo-defender' ); ?></span>
                                        <?php else : ?>
                                            <span class="ccm-wd-badge ccm-wd-badge-success"><?php esc_html_e( 'Allowed', 'ccm-woo-defender' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ccm-wd-history-reasons">
                                        <?php if ( ! empty( $reason_tags ) ) : ?>
                                            <?php foreach ( $reason_tags as $tag ) : ?>
                                                <span class="ccm-wd-reason-tag"><?php echo esc_html( $tag ); ?></span>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <span class="ccm-wd-text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination (bottom) -->
                <?php if ( $total_pages > 1 ) : ?>
                    <?php $this->render_pagination( $current, $total_pages ); ?>
                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render pagination controls for the history tab.
     */
    private function render_pagination( int $current, int $total_pages ): void {
        $base_url = admin_url( 'admin.php?page=ccm-woo-defender&tab=history' );
        ?>
        <div class="ccm-wd-pagination">
            <?php if ( $current > 1 ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'paged', $current - 1, $base_url ) ); ?>" class="ccm-wd-button ccm-wd-button-small ccm-wd-button-secondary">&laquo; <?php esc_html_e( 'Previous', 'ccm-woo-defender' ); ?></a>
            <?php else : ?>
                <span class="ccm-wd-button ccm-wd-button-small ccm-wd-button-secondary" style="opacity: 0.4; pointer-events: none;">&laquo; <?php esc_html_e( 'Previous', 'ccm-woo-defender' ); ?></span>
            <?php endif; ?>

            <span class="ccm-wd-pagination-info">
                <?php
                printf(
                    /* translators: %1$d = current page, %2$d = total pages */
                    esc_html__( 'Page %1$d of %2$d', 'ccm-woo-defender' ),
                    $current,
                    $total_pages
                );
                ?>
            </span>

            <?php if ( $current < $total_pages ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'paged', $current + 1, $base_url ) ); ?>" class="ccm-wd-button ccm-wd-button-small ccm-wd-button-secondary"><?php esc_html_e( 'Next', 'ccm-woo-defender' ); ?> &raquo;</a>
            <?php else : ?>
                <span class="ccm-wd-button ccm-wd-button-small ccm-wd-button-secondary" style="opacity: 0.4; pointer-events: none;"><?php esc_html_e( 'Next', 'ccm-woo-defender' ); ?> &raquo;</span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<string, int|bool|string> $settings
     */
    private function render_settings( array $settings ): void {
        $profiles = $this->settings->get_profile_labels();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ccm_wd_save_settings" />
            <?php wp_nonce_field( 'ccm_wd_save_settings' ); ?>

            <!-- Easy Setup Card -->
            <div class="ccm-wd-card">
                <h2><?php esc_html_e( 'Easy Setup', 'ccm-woo-defender' ); ?></h2>
                <p><?php esc_html_e( 'Start here: choose a preset and save. Most stores should use Balanced.', 'ccm-woo-defender' ); ?></p>

                <!-- Enable Protection -->
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong><?php esc_html_e( 'Enable Protection', 'ccm-woo-defender' ); ?></strong>
                        <p class="ccm-wd-text-muted"><?php esc_html_e( 'Block high-risk checkout attempts automatically.', 'ccm-woo-defender' ); ?></p>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <label class="ccm-wd-toggle">
                            <input type="checkbox" name="ccm_wd_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
                            <span class="ccm-wd-toggle-slider"></span>
                        </label>
                    </div>
                </div>

                <!-- Protection Preset -->
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong><?php esc_html_e( 'Protection Preset', 'ccm-woo-defender' ); ?></strong>
                        <p class="ccm-wd-text-muted"><?php esc_html_e( 'Balanced is recommended for most stores.', 'ccm-woo-defender' ); ?></p>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <select name="ccm_wd_settings[profile]" class="ccm-wd-select">
                            <?php foreach ( $profiles as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $settings['profile'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Block Duration -->
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong><?php esc_html_e( 'Block Duration (hours)', 'ccm-woo-defender' ); ?></strong>
                        <p class="ccm-wd-text-muted"><?php esc_html_e( 'How long a blocked fingerprint stays blocked.', 'ccm-woo-defender' ); ?></p>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="1" max="720" name="ccm_wd_settings[block_duration_hours]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['block_duration_hours'] ); ?>" />
                    </div>
                </div>

                <!-- Detection Window -->
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong><?php esc_html_e( 'Detection Window (hours)', 'ccm-woo-defender' ); ?></strong>
                        <p class="ccm-wd-text-muted"><?php esc_html_e( 'How far back to look for related checkout attempts.', 'ccm-woo-defender' ); ?></p>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="1" max="168" name="ccm_wd_settings[lookback_hours]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['lookback_hours'] ); ?>" />
                    </div>
                </div>

                <!-- Advanced Mode -->
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong><?php esc_html_e( 'Advanced Mode', 'ccm-woo-defender' ); ?></strong>
                        <p class="ccm-wd-text-muted"><?php esc_html_e( 'Enable expert controls for weights and trigger thresholds.', 'ccm-woo-defender' ); ?></p>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <label class="ccm-wd-toggle">
                            <input type="checkbox" id="ccm-wd-advanced-mode" name="ccm_wd_settings[advanced_mode]" value="1" <?php checked( ! empty( $settings['advanced_mode'] ) ); ?> />
                            <span class="ccm-wd-toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Advanced Detection Controls Card -->
            <div id="ccm-wd-advanced-card" class="ccm-wd-card" style="<?php echo empty( $settings['advanced_mode'] ) ? 'display:none;' : ''; ?>">
                <h2><?php esc_html_e( 'Advanced Detection Controls', 'ccm-woo-defender' ); ?></h2>
                <p class="ccm-wd-text-muted"><?php esc_html_e( 'These values override preset defaults. Lower thresholds and higher weights increase strictness.', 'ccm-woo-defender' ); ?></p>

                <h3><?php esc_html_e( 'Risk Threshold', 'ccm-woo-defender' ); ?></h3>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Risk Threshold', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="threshold" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                        <p class="ccm-wd-text-muted"><?php esc_html_e( 'Lower = stricter. Recommended range: 60 to 90.', 'ccm-woo-defender' ); ?></p>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="20" max="200" name="ccm_wd_settings[threshold]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['threshold'] ); ?>" />
                    </div>
                </div>

                <h3><?php esc_html_e( 'Signal Weights', 'ccm-woo-defender' ); ?></h3>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Suspicious address', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="weight_suspicious_address" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="0" max="100" name="ccm_wd_settings[weight_suspicious_address]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['weight_suspicious_address'] ); ?>" />
                    </div>
                </div>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Gateway + amount identity churn', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="weight_payment_identity_churn" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="0" max="100" name="ccm_wd_settings[weight_payment_identity_churn]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['weight_payment_identity_churn'] ); ?>" />
                    </div>
                </div>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Same IP identity churn', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="weight_ip_identity_churn" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="0" max="100" name="ccm_wd_settings[weight_ip_identity_churn]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['weight_ip_identity_churn'] ); ?>" />
                    </div>
                </div>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Same device identity churn', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="weight_device_identity_churn" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="0" max="100" name="ccm_wd_settings[weight_device_identity_churn]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['weight_device_identity_churn'] ); ?>" />
                    </div>
                </div>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Repeat-after-blocks', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="weight_repeat_after_blocks" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="0" max="100" name="ccm_wd_settings[weight_repeat_after_blocks]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['weight_repeat_after_blocks'] ); ?>" />
                    </div>
                </div>

                <h3><?php esc_html_e( 'Trigger Thresholds', 'ccm-woo-defender' ); ?></h3>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Gateway + amount min attempts', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="payment_identity_min_attempts" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="2" max="30" name="ccm_wd_settings[payment_identity_min_attempts]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['payment_identity_min_attempts'] ); ?>" />
                    </div>
                </div>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Gateway + amount min unique emails', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="payment_identity_min_unique_emails" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="2" max="20" name="ccm_wd_settings[payment_identity_min_unique_emails]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['payment_identity_min_unique_emails'] ); ?>" />
                    </div>
                </div>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Same IP min attempts', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="ip_identity_min_attempts" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="2" max="30" name="ccm_wd_settings[ip_identity_min_attempts]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['ip_identity_min_attempts'] ); ?>" />
                    </div>
                </div>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Same IP min unique addresses', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="ip_identity_min_unique_addresses" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="2" max="20" name="ccm_wd_settings[ip_identity_min_unique_addresses]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['ip_identity_min_unique_addresses'] ); ?>" />
                    </div>
                </div>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Same device min attempts', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="device_identity_min_attempts" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="2" max="30" name="ccm_wd_settings[device_identity_min_attempts]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['device_identity_min_attempts'] ); ?>" />
                    </div>
                </div>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Same device min unique emails', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="device_identity_min_unique_emails" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="2" max="20" name="ccm_wd_settings[device_identity_min_unique_emails]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['device_identity_min_unique_emails'] ); ?>" />
                    </div>
                </div>
                <div class="ccm-wd-setting-row">
                    <div class="ccm-wd-setting-info">
                        <strong>
                            <?php esc_html_e( 'Repeat-after-blocks min attempts', 'ccm-woo-defender' ); ?>
                            <button type="button" class="ccm-wd-info-btn" data-modal="repeat_after_blocks_min_attempts" title="<?php esc_attr_e( 'More info', 'ccm-woo-defender' ); ?>"><span class="dashicons dashicons-info-outline"></span></button>
                        </strong>
                    </div>
                    <div class="ccm-wd-setting-control">
                        <input type="number" min="1" max="20" name="ccm_wd_settings[repeat_after_blocks_min_attempts]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $settings['repeat_after_blocks_min_attempts'] ); ?>" />
                    </div>
                </div>
            </div>

            <!-- GeoIP Country Blocking Card -->
            <?php $this->render_geoip_card( $settings ); ?>

            <!-- Manual Blocked IPs Card -->
            <div class="ccm-wd-card">
                <h2><?php esc_html_e( 'Blocked IPs', 'ccm-woo-defender' ); ?></h2>
                <p class="ccm-wd-text-muted"><?php esc_html_e( 'Manually block specific IP addresses from completing checkout.', 'ccm-woo-defender' ); ?></p>
                <div class="ccm-wd-setting-row" style="flex-direction: column;">
                    <div class="ccm-wd-setting-info">
                        <strong><?php esc_html_e( 'Manual Blocked IP List', 'ccm-woo-defender' ); ?></strong>
                        <p class="ccm-wd-text-muted"><?php esc_html_e( 'One IP per line (IPv4 or IPv6). These addresses are always blocked at checkout before scoring.', 'ccm-woo-defender' ); ?></p>
                    </div>
                    <textarea name="ccm_wd_settings[manual_blocked_ips]" rows="5" class="ccm-wd-textarea"><?php echo esc_textarea( (string) ( $settings['manual_blocked_ips'] ?? '' ) ); ?></textarea>
                </div>
            </div>

            <!-- Info Modal (shared, populated by JS) -->
            <div id="ccm-wd-modal-overlay" class="ccm-wd-modal-overlay" style="display:none;">
                <div class="ccm-wd-modal">
                    <div class="ccm-wd-modal-header">
                        <strong id="ccm-wd-modal-title"></strong>
                        <button type="button" id="ccm-wd-modal-close" class="ccm-wd-modal-close">&times;</button>
                    </div>
                    <div id="ccm-wd-modal-body" class="ccm-wd-modal-body"></div>
                </div>
            </div>

            <!-- Save / Reset Buttons -->
            <div class="ccm-wd-card">
                <div class="ccm-wd-form-actions" style="border-top: none; margin-top: 0; padding-top: 0;">
                    <button type="submit" class="ccm-wd-button"><?php esc_html_e( 'Save Settings', 'ccm-woo-defender' ); ?></button>
                </div>
            </div>
        </form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ccm_wd_reset_settings" />
            <?php wp_nonce_field( 'ccm_wd_reset_settings' ); ?>
            <div style="margin-top: -1rem;">
                <button type="submit" class="ccm-wd-button ccm-wd-button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Reset all settings to defaults?', 'ccm-woo-defender' ) ); ?>');">
                    <?php esc_html_e( 'Reset Settings to Defaults', 'ccm-woo-defender' ); ?>
                </button>
            </div>
        </form>
        <?php
    }

    /**
     * Render the GeoIP Country Blocking settings card.
     *
     * @param array<string, mixed> $settings
     */
    private function render_geoip_card( array $settings ): void {
        $countries        = CCM_WD_GeoIP::get_all_countries();
        $blocked          = (array) ( $settings['geoip_blocked_countries'] ?? array() );
        $geoip_enabled    = ! empty( $settings['geoip_enabled'] );
        $geoip_action     = (string) ( $settings['geoip_action'] ?? 'block' );
        $geoip_weight     = (int) ( $settings['geoip_weight'] ?? 80 );
        $geoip_log_only   = ! empty( $settings['geoip_log_only'] );
        $geoip_account_id = (string) ( $settings['geoip_account_id'] ?? '' );
        $geoip_license    = (string) ( $settings['geoip_license_key'] ?? '' );
        ?>
        <div class="ccm-wd-card">
            <h2><?php esc_html_e( 'Country Blocking (GeoIP)', 'ccm-woo-defender' ); ?></h2>
            <p class="ccm-wd-text-muted"><?php esc_html_e( 'Block or penalise checkout attempts originating from specific countries using MaxMind GeoLite2 IP lookups.', 'ccm-woo-defender' ); ?></p>

            <!-- API notice -->
            <div class="ccm-wd-api-notice">
                <span class="dashicons dashicons-info-outline"></span>
                <div>
                    <strong><?php esc_html_e( 'MaxMind API Setup', 'ccm-woo-defender' ); ?></strong><br>
                    <?php
                    printf(
                        /* translators: %1$s = link to MaxMind signup, %2$s = link to license key page */
                        esc_html__( '1. Create a free account at %1$s. 2. Generate a license key at %2$s (Services > Manage License Keys). 3. Enter your Account ID and License Key below.', 'ccm-woo-defender' ),
                        '<a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener">maxmind.com</a>',
                        '<a href="https://www.maxmind.com/en/accounts/current/license-key" target="_blank" rel="noopener">License Keys</a>'
                    );
                    ?>
                    <br>
                    <small><?php esc_html_e( 'The free GeoLite2 web service allows up to 1,000 lookups per day. Results are cached for 24 hours per IP to minimise usage. GeoLite2 EULA applies.', 'ccm-woo-defender' ); ?></small>
                </div>
            </div>

            <!-- Enable GeoIP -->
            <div class="ccm-wd-setting-row">
                <div class="ccm-wd-setting-info">
                    <strong><?php esc_html_e( 'Enable Country Blocking', 'ccm-woo-defender' ); ?></strong>
                    <p class="ccm-wd-text-muted"><?php esc_html_e( 'Look up the country of each checkout IP and enforce the rules below.', 'ccm-woo-defender' ); ?></p>
                </div>
                <div class="ccm-wd-setting-control">
                    <label class="ccm-wd-toggle">
                        <input type="checkbox" name="ccm_wd_settings[geoip_enabled]" value="1" <?php checked( $geoip_enabled ); ?> />
                        <span class="ccm-wd-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <!-- MaxMind Account ID -->
            <div class="ccm-wd-setting-row">
                <div class="ccm-wd-setting-info">
                    <strong><?php esc_html_e( 'MaxMind Account ID', 'ccm-woo-defender' ); ?></strong>
                    <p class="ccm-wd-text-muted"><?php esc_html_e( 'Your numeric MaxMind account ID.', 'ccm-woo-defender' ); ?></p>
                </div>
                <div class="ccm-wd-setting-control">
                    <input type="text"
                           name="ccm_wd_settings[geoip_account_id]"
                           class="ccm-wd-number-input"
                           style="width: 140px; text-align: left; font-family: var(--ccm-wd-font-mono);"
                           value="<?php echo esc_attr( $geoip_account_id ); ?>"
                           placeholder="123456"
                           autocomplete="off" />
                </div>
            </div>

            <!-- MaxMind License Key -->
            <div class="ccm-wd-setting-row">
                <div class="ccm-wd-setting-info">
                    <strong><?php esc_html_e( 'MaxMind License Key', 'ccm-woo-defender' ); ?></strong>
                    <p class="ccm-wd-text-muted"><?php esc_html_e( 'Your GeoLite2 license key (keep this secret).', 'ccm-woo-defender' ); ?></p>
                </div>
                <div class="ccm-wd-setting-control">
                    <input type="password"
                           name="ccm_wd_settings[geoip_license_key]"
                           class="ccm-wd-number-input ccm-wd-input-password"
                           style="width: 220px; text-align: left;"
                           value="<?php echo esc_attr( $geoip_license ); ?>"
                           placeholder="••••••••••••••••"
                           autocomplete="new-password" />
                </div>
            </div>

            <!-- Action Mode -->
            <div class="ccm-wd-setting-row">
                <div class="ccm-wd-setting-info">
                    <strong><?php esc_html_e( 'Blocking Action', 'ccm-woo-defender' ); ?></strong>
                    <p class="ccm-wd-text-muted"><?php esc_html_e( '"Hard block" stops checkout immediately. "Add to risk score" adds points that combine with other signals.', 'ccm-woo-defender' ); ?></p>
                </div>
                <div class="ccm-wd-setting-control">
                    <select name="ccm_wd_settings[geoip_action]" class="ccm-wd-select">
                        <option value="block" <?php selected( $geoip_action, 'block' ); ?>><?php esc_html_e( 'Hard block', 'ccm-woo-defender' ); ?></option>
                        <option value="score" <?php selected( $geoip_action, 'score' ); ?>><?php esc_html_e( 'Add to risk score', 'ccm-woo-defender' ); ?></option>
                    </select>
                </div>
            </div>

            <!-- GeoIP Weight (only matters in 'score' mode) -->
            <div class="ccm-wd-setting-row">
                <div class="ccm-wd-setting-info">
                    <strong><?php esc_html_e( 'Country Risk Score', 'ccm-woo-defender' ); ?></strong>
                    <p class="ccm-wd-text-muted"><?php esc_html_e( 'Points added to the risk score when action is "Add to risk score". Higher = more likely to trigger a block.', 'ccm-woo-defender' ); ?></p>
                </div>
                <div class="ccm-wd-setting-control">
                    <input type="number" min="10" max="200" name="ccm_wd_settings[geoip_weight]" class="ccm-wd-number-input" value="<?php echo esc_attr( (string) $geoip_weight ); ?>" />
                </div>
            </div>

            <!-- Log-Only / Dry-Run Mode -->
            <div class="ccm-wd-setting-row">
                <div class="ccm-wd-setting-info">
                    <strong><?php esc_html_e( 'Log Only (Dry Run)', 'ccm-woo-defender' ); ?></strong>
                    <p class="ccm-wd-text-muted"><?php esc_html_e( 'Log the detected country in events without blocking or scoring. Useful for testing your MaxMind setup.', 'ccm-woo-defender' ); ?></p>
                </div>
                <div class="ccm-wd-setting-control">
                    <label class="ccm-wd-toggle">
                        <input type="checkbox" name="ccm_wd_settings[geoip_log_only]" value="1" <?php checked( $geoip_log_only ); ?> />
                        <span class="ccm-wd-toggle-slider"></span>
                    </label>
                </div>
            </div>

            <!-- Country Selection -->
            <h3><?php esc_html_e( 'Blocked Countries', 'ccm-woo-defender' ); ?></h3>
            <p class="ccm-wd-text-muted"><?php esc_html_e( 'Check countries to block or penalise. Unchecked countries are allowed.', 'ccm-woo-defender' ); ?></p>

            <div class="ccm-wd-country-toolbar">
                <input type="text"
                       id="ccm-wd-country-search"
                       class="ccm-wd-country-search"
                       placeholder="<?php esc_attr_e( 'Search countries…', 'ccm-woo-defender' ); ?>" />
                <button type="button" id="ccm-wd-country-select-all" class="ccm-wd-country-btn"><?php esc_html_e( 'Select All', 'ccm-woo-defender' ); ?></button>
                <button type="button" id="ccm-wd-country-deselect-all" class="ccm-wd-country-btn"><?php esc_html_e( 'Deselect All', 'ccm-woo-defender' ); ?></button>
                <span id="ccm-wd-country-count" class="ccm-wd-country-count"></span>
            </div>

            <div id="ccm-wd-country-grid" class="ccm-wd-country-grid">
                <?php foreach ( $countries as $code => $name ) :
                    $is_blocked = in_array( $code, $blocked, true );
                ?>
                    <label class="ccm-wd-country-item<?php echo $is_blocked ? ' is-checked' : ''; ?>"
                           data-code="<?php echo esc_attr( $code ); ?>"
                           data-name="<?php echo esc_attr( $name ); ?>">
                        <input type="checkbox"
                               name="ccm_wd_settings[geoip_blocked_countries][]"
                               value="<?php echo esc_attr( $code ); ?>"
                               <?php checked( $is_blocked ); ?> />
                        <span class="ccm-wd-country-code"><?php echo esc_html( $code ); ?></span>
                        <span class="ccm-wd-country-name"><?php echo esc_html( $name ); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}