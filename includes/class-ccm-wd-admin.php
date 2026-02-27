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

        if ( ! in_array( $selected_tab, array( 'overview', 'settings' ), true ) ) {
            $selected_tab = 'overview';
        }
        ?>
        <div class="wrap ccm-wd">

            <div class="ccm-wd-header">
                <div class="ccm-wd-header-brand">
                    <h1><?php esc_html_e( 'CCM Woo Defender', 'ccm-woo-defender' ); ?></h1>
                    <span class="ccm-wd-version">v<?php echo esc_html( CCM_WD_VERSION ); ?></span>
                </div>
                <div class="ccm-wd-tabs">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ccm-woo-defender&tab=overview' ) ); ?>" class="ccm-wd-tab <?php echo 'overview' === $selected_tab ? 'active' : ''; ?>"><?php esc_html_e( 'Overview', 'ccm-woo-defender' ); ?></a>
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
        $last       = $this->store->get_last_request_context();
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
            <div class="ccm-wd-stat-box">
                <span class="ccm-wd-stat-value">
                    <?php if ( ! empty( $stats['force_block_on'] ) ) : ?>
                        <span class="ccm-wd-badge ccm-wd-badge-error"><?php esc_html_e( 'Active', 'ccm-woo-defender' ); ?></span>
                    <?php else : ?>
                        <span class="ccm-wd-badge ccm-wd-badge-neutral"><?php esc_html_e( 'Off', 'ccm-woo-defender' ); ?></span>
                    <?php endif; ?>
                </span>
                <span class="ccm-wd-stat-label"><?php esc_html_e( 'Force Block', 'ccm-woo-defender' ); ?></span>
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

        <!-- Last Checkout Request -->
        <div class="ccm-wd-card">
            <h2><?php esc_html_e( 'Last Checkout Request', 'ccm-woo-defender' ); ?></h2>
            <?php if ( empty( $last ) ) : ?>
                <p class="ccm-wd-text-muted"><?php esc_html_e( 'No checkout request captured yet.', 'ccm-woo-defender' ); ?></p>
            <?php else : ?>
                <table class="ccm-wd-table">
                    <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Hook', 'ccm-woo-defender' ); ?></th>
                        <td><?php echo esc_html( (string) ( $last['hook'] ?? '' ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Blocked', 'ccm-woo-defender' ); ?></th>
                        <td>
                            <?php if ( ! empty( $last['blocked'] ) ) : ?>
                                <span class="ccm-wd-badge ccm-wd-badge-error"><?php esc_html_e( 'Yes', 'ccm-woo-defender' ); ?></span>
                            <?php else : ?>
                                <span class="ccm-wd-badge ccm-wd-badge-success"><?php esc_html_e( 'No', 'ccm-woo-defender' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Reason', 'ccm-woo-defender' ); ?></th>
                        <td><?php echo esc_html( (string) ( $last['reason'] ?? '' ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Resolved Client IP', 'ccm-woo-defender' ); ?></th>
                        <td><code><?php echo esc_html( (string) ( $last['resolved_client_ip'] ?? '' ) ); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'REMOTE_ADDR', 'ccm-woo-defender' ); ?></th>
                        <td><code><?php echo esc_html( (string) ( $last['remote_addr'] ?? '' ) ); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'X-Forwarded-For', 'ccm-woo-defender' ); ?></th>
                        <td><code><?php echo esc_html( (string) ( $last['http_x_forwarded_for'] ?? '' ) ); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'CF-Connecting-IP', 'ccm-woo-defender' ); ?></th>
                        <td><code><?php echo esc_html( (string) ( $last['http_cf_connecting_ip'] ?? '' ) ); ?></code></td>
                    </tr>
                    </tbody>
                </table>
            <?php endif; ?>
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

                <!-- Manual Blocked IPs -->
                <div class="ccm-wd-setting-row" style="flex-direction: column;">
                    <div class="ccm-wd-setting-info">
                        <strong><?php esc_html_e( 'Manual Blocked IP List', 'ccm-woo-defender' ); ?></strong>
                        <p class="ccm-wd-text-muted"><?php esc_html_e( 'One IP per line (IPv4 or IPv6). These addresses are always blocked at checkout before scoring.', 'ccm-woo-defender' ); ?></p>
                    </div>
                    <textarea name="ccm_wd_settings[manual_blocked_ips]" rows="5" class="ccm-wd-textarea"><?php echo esc_textarea( (string) ( $settings['manual_blocked_ips'] ?? '' ) ); ?></textarea>
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
}