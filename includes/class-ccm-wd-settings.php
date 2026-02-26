<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Settings {
    private const OPTION_SETTINGS = 'ccm_wd_settings';

    /**
     * @return array<string, int|bool>
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
     * @return array<string, int|bool>
     */
    public function update( array $raw ): array {
        $clean = $this->sanitize( $raw );
        update_option( self::OPTION_SETTINGS, $clean, false );
        return $this->get();
    }

    /**
     * @return array<string, int|bool>
     */
    public function defaults(): array {
        return array(
            'enabled'                                  => true,
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
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, int|bool>
     */
    private function sanitize( array $raw ): array {
        $defaults = $this->defaults();

        return array(
            'enabled'                                  => ! empty( $raw['enabled'] ),
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
        );
    }

    /**
     * @param mixed $value
     */
    private function bounded_int( $value, int $min, int $max ): int {
        $number = absint( (string) $value );
        return max( $min, min( $max, $number ) );
    }
}
