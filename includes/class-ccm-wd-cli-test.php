<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_CLI_Test {
    public static function register(): void {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'ccm-wd simulate', array( __CLASS__, 'simulate' ) );
        }
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
     * ## EXAMPLES
     *
     *     wp ccm-wd simulate
     *     wp ccm-wd simulate --attempts=8 --gateway=paypal --total=139.20 --clear-first=1
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public static function simulate( array $args, array $assoc_args ): void {
        $store    = new CCM_WD_Store();
        $settings = new CCM_WD_Settings();
        $analyzer = new CCM_WD_Analyzer( $store, $settings );

        $attempts    = max( 2, min( 25, absint( $assoc_args['attempts'] ?? 6 ) ) );
        $gateway     = sanitize_key( (string) ( $assoc_args['gateway'] ?? 'bacs' ) );
        $country     = strtoupper( sanitize_text_field( (string) ( $assoc_args['country'] ?? 'AU' ) ) );
        $ip          = sanitize_text_field( (string) ( $assoc_args['ip'] ?? '169.148.67.2' ) );
        $clear_first = '0' !== (string) ( $assoc_args['clear-first'] ?? '1' );

        $total_value = (float) ( $assoc_args['total'] ?? 139.2 );
        $total       = number_format( $total_value, 2, '.', '' );

        if ( $clear_first ) {
            $store->clear_events();
            $store->clear_blocks();
            \WP_CLI::log( 'Cleared existing Defender events and blocks.' );
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
                'Simulation complete. Tracked attempts: %d, blocked attempts: %d, active block tokens: %d',
                (int) $stats['events_total'],
                (int) $stats['events_blocked'],
                (int) $stats['active_blocks']
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
}
