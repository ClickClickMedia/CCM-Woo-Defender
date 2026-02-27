<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Settings {
    private const OPTION_SETTINGS = 'ccm_wd_settings';

    /**
    * @return array<string, int|bool|string>
     */
    public function get(): array {
        $saved = get_option( self::OPTION_SETTINGS, array() );

        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $this->sanitize( $saved ), $this->defaults() );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, int|bool|string>
     */
    public function update( array $raw ): array {
        $clean = $this->sanitize( $raw );
        update_option( self::OPTION_SETTINGS, $clean, false );
        return $this->get();
    }

    /**
     * @return array<string, int|bool>
     */
    public function get_effective_detection_settings(): array {
        $settings = $this->get();
        $profile  = $this->get_profile_config( (string) $settings['profile'] );

        if ( ! empty( $settings['advanced_mode'] ) ) {
            $profile = array_merge(
                $profile,
                array(
                    'threshold'                          => (int) $settings['threshold'],
                    'weight_suspicious_address'          => (int) $settings['weight_suspicious_address'],
                    'weight_payment_identity_churn'      => (int) $settings['weight_payment_identity_churn'],
                    'weight_ip_identity_churn'           => (int) $settings['weight_ip_identity_churn'],
                    'weight_device_identity_churn'       => (int) $settings['weight_device_identity_churn'],
                    'weight_repeat_after_blocks'         => (int) $settings['weight_repeat_after_blocks'],
                    'payment_identity_min_attempts'      => (int) $settings['payment_identity_min_attempts'],
                    'payment_identity_min_unique_emails' => (int) $settings['payment_identity_min_unique_emails'],
                    'ip_identity_min_attempts'           => (int) $settings['ip_identity_min_attempts'],
                    'ip_identity_min_unique_addresses'   => (int) $settings['ip_identity_min_unique_addresses'],
                    'device_identity_min_attempts'       => (int) $settings['device_identity_min_attempts'],
                    'device_identity_min_unique_emails'  => (int) $settings['device_identity_min_unique_emails'],
                    'repeat_after_blocks_min_attempts'   => (int) $settings['repeat_after_blocks_min_attempts'],
                )
            );
        }

        return $profile;
    }

    /**
     * @return array<string, string>
     */
    public function get_profile_labels(): array {
        return array(
            'lenient'  => __( 'Lenient (fewer blocks)', 'ccm-woo-defender' ),
            'balanced' => __( 'Balanced (recommended)', 'ccm-woo-defender' ),
            'strict'   => __( 'Strict (maximum protection)', 'ccm-woo-defender' ),
        );
    }

    /**
     * @return array<string, int|bool|string>
     */
    public function defaults(): array {
        return array(
            'enabled'                                  => true,
            'manual_blocked_ips'                       => '',
            'advanced_mode'                            => false,
            'profile'                                  => 'balanced',
            'threshold'                                => 70,
            'block_duration_hours'                     => 168,
            'lookback_hours'                           => 24,
            'weight_suspicious_address'                => 20,
            'weight_payment_identity_churn'            => 45,
            'weight_ip_identity_churn'                 => 40,
            'weight_device_identity_churn'             => 30,
            'weight_repeat_after_blocks'               => 30,
            'payment_identity_min_attempts'            => 4,
            'payment_identity_min_unique_emails'       => 3,
            'ip_identity_min_attempts'                 => 5,
            'ip_identity_min_unique_addresses'         => 3,
            'device_identity_min_attempts'             => 7,
            'device_identity_min_unique_emails'        => 4,
            'repeat_after_blocks_min_attempts'         => 2,
            // GeoIP / Country Blocking.
            'geoip_enabled'                            => false,
            'geoip_account_id'                         => '',
            'geoip_license_key'                        => '',
            'geoip_action'                             => 'block',   // 'block' = hard block, 'score' = add risk score.
            'geoip_weight'                             => 80,        // Risk score added when action = 'score'.
            'geoip_blocked_countries'                  => array(),   // Array of ISO codes to block/penalise.
            'geoip_log_only'                           => false,     // Log country without blocking (dry-run mode).
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, int|bool|string>
     */
    private function sanitize( array $raw ): array {
        $defaults = $this->defaults();
        $profile  = sanitize_key( (string) ( $raw['profile'] ?? $defaults['profile'] ) );

        if ( ! in_array( $profile, array( 'lenient', 'balanced', 'strict' ), true ) ) {
            $profile = (string) $defaults['profile'];
        }

        return array(
            'enabled'                                  => ! empty( $raw['enabled'] ),
            'manual_blocked_ips'                       => $this->sanitize_ip_list_text( (string) ( $raw['manual_blocked_ips'] ?? $defaults['manual_blocked_ips'] ) ),
            'advanced_mode'                            => ! empty( $raw['advanced_mode'] ),
            'profile'                                  => $profile,
            'threshold'                                => $this->bounded_int( $raw['threshold'] ?? $defaults['threshold'], 20, 200 ),
            'block_duration_hours'                     => $this->bounded_int( $raw['block_duration_hours'] ?? $defaults['block_duration_hours'], 1, 720 ),
            'lookback_hours'                           => $this->bounded_int( $raw['lookback_hours'] ?? $defaults['lookback_hours'], 1, 168 ),
            'weight_suspicious_address'                => $this->bounded_int( $raw['weight_suspicious_address'] ?? $defaults['weight_suspicious_address'], 0, 100 ),
            'weight_payment_identity_churn'            => $this->bounded_int( $raw['weight_payment_identity_churn'] ?? $defaults['weight_payment_identity_churn'], 0, 100 ),
            'weight_ip_identity_churn'                 => $this->bounded_int( $raw['weight_ip_identity_churn'] ?? $defaults['weight_ip_identity_churn'], 0, 100 ),
            'weight_device_identity_churn'             => $this->bounded_int( $raw['weight_device_identity_churn'] ?? $defaults['weight_device_identity_churn'], 0, 100 ),
            'weight_repeat_after_blocks'               => $this->bounded_int( $raw['weight_repeat_after_blocks'] ?? $defaults['weight_repeat_after_blocks'], 0, 100 ),
            'payment_identity_min_attempts'            => $this->bounded_int( $raw['payment_identity_min_attempts'] ?? $defaults['payment_identity_min_attempts'], 2, 30 ),
            'payment_identity_min_unique_emails'       => $this->bounded_int( $raw['payment_identity_min_unique_emails'] ?? $defaults['payment_identity_min_unique_emails'], 2, 20 ),
            'ip_identity_min_attempts'                 => $this->bounded_int( $raw['ip_identity_min_attempts'] ?? $defaults['ip_identity_min_attempts'], 2, 30 ),
            'ip_identity_min_unique_addresses'         => $this->bounded_int( $raw['ip_identity_min_unique_addresses'] ?? $defaults['ip_identity_min_unique_addresses'], 2, 20 ),
            'device_identity_min_attempts'             => $this->bounded_int( $raw['device_identity_min_attempts'] ?? $defaults['device_identity_min_attempts'], 2, 30 ),
            'device_identity_min_unique_emails'        => $this->bounded_int( $raw['device_identity_min_unique_emails'] ?? $defaults['device_identity_min_unique_emails'], 2, 20 ),
            'repeat_after_blocks_min_attempts'         => $this->bounded_int( $raw['repeat_after_blocks_min_attempts'] ?? $defaults['repeat_after_blocks_min_attempts'], 1, 20 ),
            // GeoIP / Country Blocking.
            'geoip_enabled'                            => ! empty( $raw['geoip_enabled'] ),
            'geoip_account_id'                         => sanitize_text_field( (string) ( $raw['geoip_account_id'] ?? $defaults['geoip_account_id'] ) ),
            'geoip_license_key'                        => sanitize_text_field( (string) ( $raw['geoip_license_key'] ?? $defaults['geoip_license_key'] ) ),
            'geoip_action'                             => in_array( ( $raw['geoip_action'] ?? '' ), array( 'block', 'score' ), true ) ? (string) $raw['geoip_action'] : (string) $defaults['geoip_action'],
            'geoip_weight'                             => $this->bounded_int( $raw['geoip_weight'] ?? $defaults['geoip_weight'], 10, 200 ),
            'geoip_blocked_countries'                  => $this->sanitize_country_list( $raw['geoip_blocked_countries'] ?? $defaults['geoip_blocked_countries'] ),
            'geoip_log_only'                           => ! empty( $raw['geoip_log_only'] ),
        );
    }

    /**
     * @param mixed $value
     */
    private function bounded_int( $value, int $min, int $max ): int {
        $number = absint( (string) $value );
        return max( $min, min( $max, $number ) );
    }

    /**
     * @return array<int, string>
     */
    public function get_manual_blocked_ips(): array {
        $settings = $this->get();
        $raw      = (string) ( $settings['manual_blocked_ips'] ?? '' );

        if ( '' === trim( $raw ) ) {
            return array();
        }

        $items = preg_split( '/[\r\n,]+/', $raw ) ?: array();
        $ips   = array();

        foreach ( $items as $item ) {
            $ip = trim( $item );

            if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                continue;
            }

            $ips[ $ip ] = true;
        }

        return array_keys( $ips );
    }

    public function is_ip_manually_blocked( string $ip ): bool {
        if ( '' === $ip ) {
            return false;
        }

        return in_array( $ip, $this->get_manual_blocked_ips(), true );
    }

    /**
     * Sanitize the country code list — keep only valid 2-letter ISO codes.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    private function sanitize_country_list( $value ): array {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $clean = array();

        foreach ( $value as $code ) {
            $code = strtoupper( sanitize_key( (string) $code ) );
            if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
                $clean[] = $code;
            }
        }

        return array_values( array_unique( $clean ) );
    }

    /**
     * Check whether a country code is in the blocked list.
     */
    public function is_country_blocked( string $country_code ): bool {
        if ( '' === $country_code ) {
            return false;
        }

        $settings = $this->get();

        if ( empty( $settings['geoip_enabled'] ) ) {
            return false;
        }

        $blocked = (array) ( $settings['geoip_blocked_countries'] ?? array() );

        return in_array( strtoupper( $country_code ), $blocked, true );
    }

    private function sanitize_ip_list_text( string $value ): string {
        $items = preg_split( '/[\r\n,]+/', $value ) ?: array();
        $ips   = array();

        foreach ( $items as $item ) {
            $ip = trim( (string) $item );

            if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                continue;
            }

            $ips[ $ip ] = true;
        }

        return implode( "\n", array_keys( $ips ) );
    }

    /**
     * @return array<string, int>
     */
    private function get_profile_config( string $profile ): array {
        $profiles = array(
            'lenient'  => array(
                'threshold'                          => 85,
                'weight_suspicious_address'          => 15,
                'weight_payment_identity_churn'      => 35,
                'weight_ip_identity_churn'           => 30,
                'weight_device_identity_churn'       => 20,
                'weight_repeat_after_blocks'         => 25,
                'payment_identity_min_attempts'      => 6,
                'payment_identity_min_unique_emails' => 4,
                'ip_identity_min_attempts'           => 7,
                'ip_identity_min_unique_addresses'   => 4,
                'device_identity_min_attempts'       => 9,
                'device_identity_min_unique_emails'  => 5,
                'repeat_after_blocks_min_attempts'   => 3,
            ),
            'balanced' => array(
                'threshold'                          => 70,
                'weight_suspicious_address'          => 20,
                'weight_payment_identity_churn'      => 45,
                'weight_ip_identity_churn'           => 40,
                'weight_device_identity_churn'       => 30,
                'weight_repeat_after_blocks'         => 30,
                'payment_identity_min_attempts'      => 4,
                'payment_identity_min_unique_emails' => 3,
                'ip_identity_min_attempts'           => 5,
                'ip_identity_min_unique_addresses'   => 3,
                'device_identity_min_attempts'       => 7,
                'device_identity_min_unique_emails'  => 4,
                'repeat_after_blocks_min_attempts'   => 2,
            ),
            'strict'   => array(
                'threshold'                          => 60,
                'weight_suspicious_address'          => 25,
                'weight_payment_identity_churn'      => 55,
                'weight_ip_identity_churn'           => 50,
                'weight_device_identity_churn'       => 40,
                'weight_repeat_after_blocks'         => 40,
                'payment_identity_min_attempts'      => 3,
                'payment_identity_min_unique_emails' => 2,
                'ip_identity_min_attempts'           => 4,
                'ip_identity_min_unique_addresses'   => 2,
                'device_identity_min_attempts'       => 5,
                'device_identity_min_unique_emails'  => 3,
                'repeat_after_blocks_min_attempts'   => 1,
            ),
        );

        return $profiles[ $profile ] ?? $profiles['balanced'];
    }
}
