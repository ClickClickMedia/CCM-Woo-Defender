<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Admin {
    private CCM_WD_Store $store;

    public function __construct( CCM_WD_Store $store ) {
        $this->store = $store;
    }

    public function register_hooks(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_ccm_wd_clear_data', array( $this, 'handle_clear_data' ) );
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

    public function handle_clear_data(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'ccm-woo-defender' ) );
        }

        check_admin_referer( 'ccm_wd_clear_data' );

        $this->store->clear_blocks();
        $this->store->clear_events();

        wp_safe_redirect( admin_url( 'admin.php?page=ccm-woo-defender&cleared=1' ) );
        exit;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $stats = $this->store->get_stats();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'CCM Woo Defender', 'ccm-woo-defender' ); ?></h1>
            <?php if ( isset( $_GET['cleared'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Defender events and blocks were cleared.', 'ccm-woo-defender' ); ?></p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width: 720px; margin-top: 16px;">
                <tbody>
                <tr>
                    <th><?php esc_html_e( 'Plugin version', 'ccm-woo-defender' ); ?></th>
                    <td><?php echo esc_html( CCM_WD_VERSION ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Tracked checkout attempts', 'ccm-woo-defender' ); ?></th>
                    <td><?php echo esc_html( (string) $stats['events_total'] ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Blocked attempts', 'ccm-woo-defender' ); ?></th>
                    <td><?php echo esc_html( (string) $stats['events_blocked'] ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Active block tokens', 'ccm-woo-defender' ); ?></th>
                    <td><?php echo esc_html( (string) $stats['active_blocks'] ); ?></td>
                </tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 16px;">
                <input type="hidden" name="action" value="ccm_wd_clear_data" />
                <?php wp_nonce_field( 'ccm_wd_clear_data' ); ?>
                <?php submit_button( __( 'Clear Defender Data', 'ccm-woo-defender' ), 'delete' ); ?>
            </form>
        </div>
        <?php
    }
}
