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
            <p><?php esc_html_e( 'Defender stops repeated fake checkout attempts by combining multiple risk signals (not just rate limits), scoring each attempt, and blocking high-risk patterns before payment is processed.', 'ccm-woo-defender' ); ?></p>

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
     * @param array<string, int|bool|string> $settings
     */
    private function render_overview( array $stats, array $settings ): void {
        $manual_ips = $this->settings->get_manual_blocked_ips();
        $last       = $this->store->get_last_request_context();
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
                <th><?php esc_html_e( 'Mode', 'ccm-woo-defender' ); ?></th>
                <td><?php echo ! empty( $settings['advanced_mode'] ) ? esc_html__( 'Advanced', 'ccm-woo-defender' ) : esc_html__( 'Easy', 'ccm-woo-defender' ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Preset profile', 'ccm-woo-defender' ); ?></th>
                <td><?php echo esc_html( ucfirst( (string) $settings['profile'] ) ); ?></td>
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
            <tr>
                <th><?php esc_html_e( 'Force block mode', 'ccm-woo-defender' ); ?></th>
                <td><?php echo ! empty( $stats['force_block_on'] ) ? esc_html__( 'Active', 'ccm-woo-defender' ) : esc_html__( 'Off', 'ccm-woo-defender' ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Manual blocked IPs', 'ccm-woo-defender' ); ?></th>
                <td><?php echo esc_html( (string) count( $manual_ips ) ); ?></td>
            </tr>
            </tbody>
        </table>

        <div style="margin-top: 12px; max-width: 760px;">
            <strong><?php esc_html_e( 'Manual blocked IP list', 'ccm-woo-defender' ); ?></strong>
            <?php if ( empty( $manual_ips ) ) : ?>
                <p><?php esc_html_e( 'No manual IPs configured.', 'ccm-woo-defender' ); ?></p>
            <?php else : ?>
                <ul style="margin-left: 18px;">
                    <?php foreach ( $manual_ips as $ip ) : ?>
                        <li><?php echo esc_html( $ip ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div style="margin-top: 12px; max-width: 760px;">
            <strong><?php esc_html_e( 'Last observed checkout request', 'ccm-woo-defender' ); ?></strong>
            <?php if ( empty( $last ) ) : ?>
                <p><?php esc_html_e( 'No checkout request captured yet.', 'ccm-woo-defender' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="margin-top:8px;">
                    <tbody>
                    <tr><th><?php esc_html_e( 'Hook', 'ccm-woo-defender' ); ?></th><td><?php echo esc_html( (string) ( $last['hook'] ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Blocked', 'ccm-woo-defender' ); ?></th><td><?php echo ! empty( $last['blocked'] ) ? esc_html__( 'Yes', 'ccm-woo-defender' ) : esc_html__( 'No', 'ccm-woo-defender' ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Reason', 'ccm-woo-defender' ); ?></th><td><?php echo esc_html( (string) ( $last['reason'] ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Resolved client IP', 'ccm-woo-defender' ); ?></th><td><?php echo esc_html( (string) ( $last['resolved_client_ip'] ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'REMOTE_ADDR', 'ccm-woo-defender' ); ?></th><td><?php echo esc_html( (string) ( $last['remote_addr'] ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'HTTP_X_FORWARDED_FOR', 'ccm-woo-defender' ); ?></th><td><?php echo esc_html( (string) ( $last['http_x_forwarded_for'] ?? '' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'HTTP_CF_CONNECTING_IP', 'ccm-woo-defender' ); ?></th><td><?php echo esc_html( (string) ( $last['http_cf_connecting_ip'] ?? '' ) ); ?></td></tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="notice notice-info" style="margin-top: 16px; max-width: 960px;">
            <p><strong><?php esc_html_e( 'How Defender works (simple version):', 'ccm-woo-defender' ); ?></strong></p>
            <ol style="margin-left: 18px;">
                <li><?php esc_html_e( 'At checkout, Defender creates a privacy-safe fingerprint from non-sensitive patterns such as payment method, amount, country, IP/device consistency, and address quality. Raw personal data is not stored.', 'ccm-woo-defender' ); ?></li>
                <li><?php esc_html_e( 'It compares this attempt with recent checkout history to detect fraud-style behavior, for example: same gateway + same amount with many different identities, same IP with multiple addresses, or repeated attempts after previous blocks.', 'ccm-woo-defender' ); ?></li>
                <li><?php esc_html_e( 'Each signal adds to a risk score. If the score reaches your configured threshold, checkout is stopped immediately and related fingerprints are temporarily blocked.', 'ccm-woo-defender' ); ?></li>
                <li><?php esc_html_e( 'Defender also learns from failed and cancelled orders, so detection becomes more accurate over time for your store’s specific abuse patterns.', 'ccm-woo-defender' ); ?></li>
            </ol>
            <p><strong><?php esc_html_e( 'Why this works better than basic rate limiting:', 'ccm-woo-defender' ); ?></strong> <?php esc_html_e( 'Many attackers deliberately spread attempts over time to bypass burst limits. Defender focuses on correlated fraud fingerprints and identity churn, which remain visible even when attempts are sporadic.', 'ccm-woo-defender' ); ?></p>
        </div>

        <p style="margin-top: 12px;"><?php esc_html_e( 'Tip: Keep protection enabled and tune threshold/weights in Settings to reduce false positives.', 'ccm-woo-defender' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 8px;">
            <input type="hidden" name="action" value="ccm_wd_clear_data" />
            <?php wp_nonce_field( 'ccm_wd_clear_data' ); ?>
            <?php submit_button( __( 'Clear Defender Data', 'ccm-woo-defender' ), 'delete' ); ?>
        </form>
        <?php
    }

    /**
     * @param array<string, int|bool|string> $settings
     */
    private function render_settings( array $settings ): void {
        $profiles = $this->settings->get_profile_labels();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 16px; max-width: 860px;">
            <input type="hidden" name="action" value="ccm_wd_save_settings" />
            <?php wp_nonce_field( 'ccm_wd_save_settings' ); ?>

            <h2><?php esc_html_e( 'Easy Setup', 'ccm-woo-defender' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Start here: choose a preset and save. Most stores should use Balanced.', 'ccm-woo-defender' ); ?></p>

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
                    <th><?php esc_html_e( 'Protection preset', 'ccm-woo-defender' ); ?></th>
                    <td>
                        <select name="ccm_wd_settings[profile]">
                            <?php foreach ( $profiles as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $settings['profile'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Balanced is recommended for most stores.', 'ccm-woo-defender' ); ?></p>
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
                    <th><?php esc_html_e( 'Advanced mode', 'ccm-woo-defender' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ccm_wd_settings[advanced_mode]" value="1" <?php checked( ! empty( $settings['advanced_mode'] ) ); ?> />
                            <?php esc_html_e( 'Enable expert controls (weights and trigger thresholds)', 'ccm-woo-defender' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Manual blocked IP list', 'ccm-woo-defender' ); ?></th>
                    <td>
                        <textarea name="ccm_wd_settings[manual_blocked_ips]" rows="6" style="width:100%; max-width:420px;"><?php echo esc_textarea( (string) ( $settings['manual_blocked_ips'] ?? '' ) ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One IP per line (IPv4 or IPv6). These addresses are always blocked at checkout before scoring.', 'ccm-woo-defender' ); ?></p>
                    </td>
                </tr>
                </tbody>
            </table>

            <?php if ( ! empty( $settings['advanced_mode'] ) ) : ?>
            <h2 style="margin-top: 18px;"><?php esc_html_e( 'Advanced Detection Controls', 'ccm-woo-defender' ); ?></h2>
            <p class="description"><?php esc_html_e( 'These values override preset defaults. Lower thresholds and higher weights increase strictness.', 'ccm-woo-defender' ); ?></p>
            <table class="widefat striped" style="margin-top: 8px;">
                <tbody>
                <tr>
                    <th><?php esc_html_e( 'Risk threshold', 'ccm-woo-defender' ); ?></th>
                    <td>
                        <input type="number" min="20" max="200" name="ccm_wd_settings[threshold]" value="<?php echo esc_attr( (string) $settings['threshold'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Lower = stricter. Recommended range: 60 to 90.', 'ccm-woo-defender' ); ?></p>
                    </td>
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
            <?php endif; ?>

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
