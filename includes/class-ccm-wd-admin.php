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
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_ccm_wd_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_ccm_wd_reset_settings', array( $this, 'handle_reset_settings' ) );
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

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $stats        = $this->store->get_stats();
        $settings     = $this->settings->get();
        $selected_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview';

        if ( ! in_array( $selected_tab, array( 'overview', 'settings' ), true ) ) {
            $selected_tab = 'overview';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'CCM Woo Defender', 'ccm-woo-defender' ); ?></h1>
            <p><?php esc_html_e( 'Checkout fraud protection for WooCommerce using local-only scoring and block controls.', 'ccm-woo-defender' ); ?></p>

            <?php if ( isset( $_GET['cleared'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Defender events and blocks were cleared.', 'ccm-woo-defender' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Defender settings saved.', 'ccm-woo-defender' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['reset'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Defender settings reset to defaults.', 'ccm-woo-defender' ); ?></p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ccm-woo-defender&tab=overview' ) ); ?>" class="nav-tab <?php echo 'overview' === $selected_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Overview', 'ccm-woo-defender' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ccm-woo-defender&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $selected_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'ccm-woo-defender' ); ?></a>
            </nav>

            <?php if ( 'overview' === $selected_tab ) : ?>
                <?php $this->render_overview( $stats, $settings ); ?>
            <?php else : ?>
                <?php $this->render_settings( $settings ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<string, int> $stats
     * @param array<string, int|bool> $settings
     */
    private function render_overview( array $stats, array $settings ): void {
        ?>
        <table class="widefat striped" style="max-width: 760px; margin-top: 16px;">
            <tbody>
            <tr>
                <th><?php esc_html_e( 'Plugin version', 'ccm-woo-defender' ); ?></th>
                <td><?php echo esc_html( CCM_WD_VERSION ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Protection status', 'ccm-woo-defender' ); ?></th>
                <td><?php echo ! empty( $settings['enabled'] ) ? esc_html__( 'Enabled', 'ccm-woo-defender' ) : esc_html__( 'Disabled', 'ccm-woo-defender' ); ?></td>
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

        <p style="margin-top: 12px;"><?php esc_html_e( 'Tip: Keep protection enabled and tune threshold/weights in Settings to reduce false positives.', 'ccm-woo-defender' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 8px;">
            <input type="hidden" name="action" value="ccm_wd_clear_data" />
            <?php wp_nonce_field( 'ccm_wd_clear_data' ); ?>
            <?php submit_button( __( 'Clear Defender Data', 'ccm-woo-defender' ), 'delete' ); ?>
        </form>
        <?php
    }

    /**
     * @param array<string, int|bool> $settings
     */
    private function render_settings( array $settings ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 16px; max-width: 860px;">
            <input type="hidden" name="action" value="ccm_wd_save_settings" />
            <?php wp_nonce_field( 'ccm_wd_save_settings' ); ?>

            <table class="widefat striped">
                <tbody>
                <tr>
                    <th><?php esc_html_e( 'Enable protection', 'ccm-woo-defender' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ccm_wd_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
                            <?php esc_html_e( 'Block high-risk checkout attempts', 'ccm-woo-defender' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Risk threshold', 'ccm-woo-defender' ); ?></th>
                    <td>
                        <input type="number" min="20" max="200" name="ccm_wd_settings[threshold]" value="<?php echo esc_attr( (string) $settings['threshold'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Lower = stricter. Recommended range: 60 to 90.', 'ccm-woo-defender' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Block duration (hours)', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="1" max="720" name="ccm_wd_settings[block_duration_hours]" value="<?php echo esc_attr( (string) $settings['block_duration_hours'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Detection window (hours)', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="1" max="168" name="ccm_wd_settings[lookback_hours]" value="<?php echo esc_attr( (string) $settings['lookback_hours'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Suspicious address weight', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="0" max="100" name="ccm_wd_settings[weight_suspicious_address]" value="<?php echo esc_attr( (string) $settings['weight_suspicious_address'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Gateway + amount identity churn weight', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="0" max="100" name="ccm_wd_settings[weight_payment_identity_churn]" value="<?php echo esc_attr( (string) $settings['weight_payment_identity_churn'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Same IP identity churn weight', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="0" max="100" name="ccm_wd_settings[weight_ip_identity_churn]" value="<?php echo esc_attr( (string) $settings['weight_ip_identity_churn'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Same device identity churn weight', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="0" max="100" name="ccm_wd_settings[weight_device_identity_churn]" value="<?php echo esc_attr( (string) $settings['weight_device_identity_churn'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Repeat-after-blocks weight', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="0" max="100" name="ccm_wd_settings[weight_repeat_after_blocks]" value="<?php echo esc_attr( (string) $settings['weight_repeat_after_blocks'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Gateway + amount min attempts', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="2" max="30" name="ccm_wd_settings[payment_identity_min_attempts]" value="<?php echo esc_attr( (string) $settings['payment_identity_min_attempts'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Gateway + amount min unique emails', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="2" max="20" name="ccm_wd_settings[payment_identity_min_unique_emails]" value="<?php echo esc_attr( (string) $settings['payment_identity_min_unique_emails'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Same IP min attempts', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="2" max="30" name="ccm_wd_settings[ip_identity_min_attempts]" value="<?php echo esc_attr( (string) $settings['ip_identity_min_attempts'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Same IP min unique addresses', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="2" max="20" name="ccm_wd_settings[ip_identity_min_unique_addresses]" value="<?php echo esc_attr( (string) $settings['ip_identity_min_unique_addresses'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Same device min attempts', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="2" max="30" name="ccm_wd_settings[device_identity_min_attempts]" value="<?php echo esc_attr( (string) $settings['device_identity_min_attempts'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Same device min unique emails', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="2" max="20" name="ccm_wd_settings[device_identity_min_unique_emails]" value="<?php echo esc_attr( (string) $settings['device_identity_min_unique_emails'] ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Repeat-after-blocks min attempts', 'ccm-woo-defender' ); ?></th>
                    <td><input type="number" min="1" max="20" name="ccm_wd_settings[repeat_after_blocks_min_attempts]" value="<?php echo esc_attr( (string) $settings['repeat_after_blocks_min_attempts'] ); ?>" /></td>
                </tr>
                </tbody>
            </table>

            <?php submit_button( __( 'Save Settings', 'ccm-woo-defender' ) ); ?>
        </form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 8px; max-width: 860px;">
            <input type="hidden" name="action" value="ccm_wd_reset_settings" />
            <?php wp_nonce_field( 'ccm_wd_reset_settings' ); ?>
            <?php submit_button( __( 'Reset Settings to Defaults', 'ccm-woo-defender' ), 'secondary' ); ?>
        </form>
        <?php
    }
}
