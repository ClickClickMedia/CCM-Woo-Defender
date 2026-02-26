<?php

defined( 'ABSPATH' ) || exit;

require_once CCM_WD_PATH . 'includes/class-ccm-wd-utils.php';
require_once CCM_WD_PATH . 'includes/class-ccm-wd-settings.php';
require_once CCM_WD_PATH . 'includes/class-ccm-wd-store.php';
require_once CCM_WD_PATH . 'includes/class-ccm-wd-analyzer.php';
require_once CCM_WD_PATH . 'includes/class-ccm-wd-checkout-guard.php';
require_once CCM_WD_PATH . 'includes/class-ccm-wd-admin.php';
require_once CCM_WD_PATH . 'includes/class-ccm-wd-cli-test.php';

class CCM_WD_Plugin {
    private static ?CCM_WD_Plugin $instance = null;

    private CCM_WD_Store $store;
    private CCM_WD_Settings $settings;
    private CCM_WD_Analyzer $analyzer;
    private CCM_WD_Checkout_Guard $guard;
    private CCM_WD_Admin $admin;

    public static function boot(): void {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
    }

    /**
     * Clean up transients and ephemeral data on deactivation.
     * Settings and event history are kept so re-activation restores the same state.
     */
    public static function on_deactivation(): void {
        global $wpdb;

        // Remove GitHub updater transients.
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ccm_wd_github_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Remove force-block (ephemeral).
        delete_option( 'ccm_wd_force_block_until' );

        // Remove last-request diagnostics (ephemeral).
        delete_option( 'ccm_wd_last_request' );
    }

    private function __construct() {
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function declare_hpos_compatibility(): void {
        if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CCM_WD_FILE, true );
        }
    }

    public function init(): void {
        if ( ! $this->is_woocommerce_active() ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        $this->store    = new CCM_WD_Store();
        $this->settings = new CCM_WD_Settings();
        $this->analyzer = new CCM_WD_Analyzer( $this->store, $this->settings );
        $this->guard    = new CCM_WD_Checkout_Guard( $this->store, $this->settings, $this->analyzer );
        $this->admin    = new CCM_WD_Admin( $this->store, $this->settings );
        $this->guard->register_hooks();
        $this->admin->register_hooks();
        CCM_WD_CLI_Test::register();
    }

    private function is_woocommerce_active(): bool {
        return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
    }

    public function woocommerce_missing_notice(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html__( 'CCM Woo Defender requires WooCommerce to be installed and active.', 'ccm-woo-defender' ) . '</p></div>';
    }
}
