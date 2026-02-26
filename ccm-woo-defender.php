<?php
/**
 * Plugin Name: CCM Woo Defender
 * Description: Lightweight fraud defense for WooCommerce checkout attempts.
 * Version: 1.00.010
 * Author: Click Click Media
 * Requires PHP: 8.1
 * Requires at least: 6.4
 * WC requires at least: 8.5
 * WC tested up to: 9.7
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'CCM_WD_VERSION' ) ) {
    define( 'CCM_WD_VERSION', '1.00.010' );
}

if ( ! defined( 'CCM_WD_FILE' ) ) {
    define( 'CCM_WD_FILE', __FILE__ );
}

if ( ! defined( 'CCM_WD_PATH' ) ) {
    define( 'CCM_WD_PATH', plugin_dir_path( __FILE__ ) );
}

require_once CCM_WD_PATH . 'includes/class-ccm-wd-plugin.php';
require_once CCM_WD_PATH . 'includes/class-ccm-wd-updater.php';

add_action(
    'plugins_loaded',
    static function (): void {
        CCM_WD_Updater::initialize( CCM_WD_FILE );
    }
);

add_action( 'admin_init', array( 'CCM_WD_Updater', 'maybe_force_refresh_on_admin_init' ), 1 );

CCM_WD_Plugin::boot();
