<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_CLI_Test {
    private static ?int $original_error_reporting = null;

    public static function register(): void {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            self::maybe_suppress_deprecations_early();
            \WP_CLI::add_command( 'ccm-wd simulate', array( __CLASS__, 'simulate' ) );
            \WP_CLI::add_command( 'ccm-wd force-block', array( __CLASS__, 'force_block' ) );
            \WP_CLI::add_command( 'ccm-wd clear-force-block', array( __CLASS__, 'clear_force_block' ) );
            \WP_CLI::add_command( 'ccm-wd force-block-status', array( __CLASS__, 'force_block_status' ) );
            \WP_CLI::add_command( 'ccm-wd runtime-ip', array( __CLASS__, 'runtime_ip' ) );
        }
    }

    private static function maybe_suppress_deprecations_early(): void {
        if ( self::is_allow_deprecations_requested() ) {
            return;
        }

        if ( null === self::$original_error_reporting ) {
            self::$original_error_reporting = error_reporting();
        }

        error_reporting( self::$original_error_reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED );
    }

    private static function is_allow_deprecations_requested(): bool {
        $argv = isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ? $_SERVER['argv'] : array();

        foreach ( $argv as $arg ) {
            if ( '--allow-deprecations=1' === $arg || '--allow-deprecations' === $arg ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simulate fraud-like checkout attempts and display scoring/block outcomes.
     *
     * ## OPTIONS
     *
     * [--attempts=<number>]
     * : Number of synthetic attempts to generate (default: 6).
     *
     * [--gateway=<gateway>]
     * : Payment gateway id used in simulation (default: bacs).
     *
     * [--total=<amount>]
     * : Order total used in simulation (default: 139.20).
     *
     * [--country=<code>]
     * : Billing country code (default: AU).
     *
     * [--ip=<ip-address>]
     * : Source IP to use for simulation (default: 169.148.67.2).
     *
     * [--clear-first=<0|1>]
     * : Clear Defender events/blocks before simulation (default: 1).
    *
    * [--profile=<lenient|balanced|strict>]
    * : Run simulation with a specific preset profile (temporary override).
    *
    * [--all-profiles=<0|1>]
    * : Run three simulations (lenient, balanced, strict) for side-by-side comparison.
    *
    * [--allow-deprecations=<0|1>]
    * : Show PHP deprecation notices during simulation (default: 0).
     *
     * ## EXAMPLES
     *
     *     wp ccm-wd simulate
     *     wp ccm-wd simulate --attempts=8 --gateway=paypal --total=139.20 --clear-first=1
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public static function simulate( array $args, array $assoc_args ): void {
        $allow_deprecations = '1' === (string) ( $assoc_args['allow-deprecations'] ?? '0' );
        $previous_reporting = error_reporting();

        if ( $allow_deprecations && null !== self::$original_error_reporting ) {
            error_reporting( self::$original_error_reporting );
        } elseif ( ! $allow_deprecations ) {
            error_reporting( $previous_reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED );
        }

        $store    = new CCM_WD_Store();
        $settings = new CCM_WD_Settings();
        $analyzer = new CCM_WD_Analyzer( $store, $settings );
        $original_settings = $settings->get();

        $attempts    = max( 2, min( 25, absint( $assoc_args['attempts'] ?? 6 ) ) );
        $gateway     = sanitize_key( (string) ( $assoc_args['gateway'] ?? 'bacs' ) );
        $country     = strtoupper( sanitize_text_field( (string) ( $assoc_args['country'] ?? 'AU' ) ) );
        $ip          = sanitize_text_field( (string) ( $assoc_args['ip'] ?? '169.148.67.2' ) );
        $clear_first = '0' !== (string) ( $assoc_args['clear-first'] ?? '1' );
        $run_all_profiles = '1' === (string) ( $assoc_args['all-profiles'] ?? '0' );
        $profile_override = isset( $assoc_args['profile'] ) ? sanitize_key( (string) $assoc_args['profile'] ) : '';

        $total_value = (float) ( $assoc_args['total'] ?? 139.2 );
        $total       = number_format( $total_value, 2, '.', '' );

        $profiles_to_run = self::resolve_profiles_to_run( $run_all_profiles, $profile_override, $original_settings );

        try {
            foreach ( $profiles_to_run as $index => $profile ) {
                self::apply_profile_override( $settings, $original_settings, $profile );

                if ( $clear_first ) {
                    $store->clear_events();
                    $store->clear_blocks();
                    \WP_CLI::log( sprintf( 'Cleared existing Defender events and blocks for profile: %s', $profile ) );
                }

                if ( count( $profiles_to_run ) > 1 ) {
                    if ( $index > 0 ) {
                        \WP_CLI::line( '' );
                    }
                    \WP_CLI::log( sprintf( 'Running simulation profile: %s', $profile ) );
                }

                $rows = array();

                for ( $i = 1; $i <= $attempts; $i++ ) {
                    $email    = 'fraud-sim-' . $i . '@example.test';
                    $name     = 'Test User ' . $i;
                    $address1 = $i . ' Invalid Street';
                    $city     = 'Sydney';
                    $postcode = '2000';
                    $ua       = 'CCM-WD-Simulator/1.0';

                    $context = self::build_context( $gateway, $country, $total, $ip, $ua, $email, $name, $address1, $city, $postcode );

                    $evaluation = $analyzer->evaluate( $context );
                    $blocked    = ! empty( $evaluation['blocked'] );

                    if ( $blocked ) {
                        $duration = (int) $settings->get()['block_duration_hours'] * HOUR_IN_SECONDS;
                        $store->block_tokens( (array) ( $evaluation['matched_tokens'] ?? array() ), $duration );
                    }

                    $store->add_event(
                        array_merge(
                            $context,
                            array(
                                'ts'      => CCM_WD_Utils::now(),
                                'blocked' => $blocked,
                                'score'   => (int) ( $evaluation['score'] ?? 0 ),
                                'reasons' => implode( ',', (array) ( $evaluation['reasons'] ?? array() ) ),
                            )
                        )
                    );

                    $rows[] = array(
                        'attempt' => (string) $i,
                        'score'   => (string) ( $evaluation['score'] ?? 0 ),
                        'blocked' => $blocked ? 'yes' : 'no',
                        'reasons' => implode( '|', (array) ( $evaluation['reasons'] ?? array() ) ),
                    );
                }

                \WP_CLI\Utils\format_items( 'table', $rows, array( 'attempt', 'score', 'blocked', 'reasons' ) );

                $stats = $store->get_stats();
                \WP_CLI::success(
                    sprintf(
                        'Profile %s complete. Tracked attempts: %d, blocked attempts: %d, active block tokens: %d',
                        $profile,
                        (int) $stats['events_total'],
                        (int) $stats['events_blocked'],
                        (int) $stats['active_blocks']
                    )
                );
            }
        } finally {
            $settings->update( $original_settings );
            error_reporting( $previous_reporting );
        }
    }

    /**
     * @param array<string, int|bool|string> $original_settings
     * @return array<int, string>
     */
    private static function resolve_profiles_to_run( bool $run_all_profiles, string $profile_override, array $original_settings ): array {
        $valid_profiles = array( 'lenient', 'balanced', 'strict' );

        if ( $run_all_profiles ) {
            return $valid_profiles;
        }

        if ( '' !== $profile_override ) {
            if ( ! in_array( $profile_override, $valid_profiles, true ) ) {
                \WP_CLI::error( 'Invalid --profile value. Use lenient, balanced, or strict.' );
            }

            return array( $profile_override );
        }

        $current_profile = (string) ( $original_settings['profile'] ?? 'balanced' );
        if ( ! in_array( $current_profile, $valid_profiles, true ) ) {
            $current_profile = 'balanced';
        }

        return array( $current_profile );
    }

    /**
     * @param array<string, int|bool|string> $original_settings
     */
    private static function apply_profile_override( CCM_WD_Settings $settings, array $original_settings, string $profile ): void {
        $settings->update(
            array_merge(
                $original_settings,
                array(
                    'advanced_mode' => false,
                    'profile'       => $profile,
                )
            )
        );
    }

    private static function build_context( string $gateway, string $country, string $total, string $ip, string $ua, string $email, string $name, string $address1, string $city, string $postcode ): array {
        $payment_signature = CCM_WD_Utils::normalize_text( $gateway . '|' . $total . '|' . $country );
        $address_signature = CCM_WD_Utils::normalize_text( $address1 . '|' . $city . '|' . $postcode . '|' . $country );

        return array(
            'gateway'      => $gateway,
            'country'      => $country,
            'total'        => $total,
            'ip_hash'      => CCM_WD_Utils::hash_token( $ip ),
            'email_hash'   => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $email ) ),
            'name_hash'    => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $name ) ),
            'address_hash' => CCM_WD_Utils::hash_token( $address_signature ),
            'ua_hash'      => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $ua ) ),
            'payment_hash' => CCM_WD_Utils::hash_token( $payment_signature ),
            'address_fake' => CCM_WD_Utils::contains_fake_address_patterns( $address1, $city, $postcode ),
        );
    }

    /**
     * Force block all checkouts for a short period to test frontend blocked UX.
     *
     * ## OPTIONS
     *
     * [--minutes=<number>]
     * : Number of minutes to force block all checkout attempts (default: 30).
     *
     * ## EXAMPLES
     *
     *     wp ccm-wd force-block --minutes=30
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public static function force_block( array $args, array $assoc_args ): void {
        $minutes = max( 1, min( 1440, absint( $assoc_args['minutes'] ?? 30 ) ) );
        $store   = new CCM_WD_Store();
        $until   = $store->set_force_block( $minutes * MINUTE_IN_SECONDS );

        \WP_CLI::success(
            sprintf(
                'Force block enabled for %d minutes. Expires at %s.',
                $minutes,
                gmdate( 'Y-m-d H:i:s', $until ) . ' UTC'
            )
        );
    }

    /**
     * Clear force block mode.
     *
     * ## EXAMPLES
     *
     *     wp ccm-wd clear-force-block
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public static function clear_force_block( array $args, array $assoc_args ): void {
        $store = new CCM_WD_Store();
        $store->clear_force_block();
        \WP_CLI::success( 'Force block disabled.' );
    }

    /**
     * Show current force block status.
     *
     * ## EXAMPLES
     *
     *     wp ccm-wd force-block-status
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public static function force_block_status( array $args, array $assoc_args ): void {
        $store = new CCM_WD_Store();

        if ( ! $store->is_force_block_active() ) {
            \WP_CLI::log( 'Force block is currently OFF.' );
            return;
        }

        $until = $store->get_force_block_until();
        \WP_CLI::log( 'Force block is ACTIVE.' );
        \WP_CLI::log( 'Expires: ' . gmdate( 'Y-m-d H:i:s', $until ) . ' UTC' );
    }

    /**
     * Show the client IP and forwarding headers as seen by Woo Defender.
     *
     * ## EXAMPLES
     *
     *     wp ccm-wd runtime-ip
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public static function runtime_ip( array $args, array $assoc_args ): void {
        $rows = array(
            array( 'field' => 'resolved_client_ip', 'value' => CCM_WD_Utils::get_client_ip() ),
            array( 'field' => 'remote_addr', 'value' => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
            array( 'field' => 'http_x_forwarded_for', 'value' => (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) ),
            array( 'field' => 'http_cf_connecting_ip', 'value' => (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ) ),
        );

        \WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
    }
}
