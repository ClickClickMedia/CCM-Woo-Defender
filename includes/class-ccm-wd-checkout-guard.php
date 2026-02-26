<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Checkout_Guard {
    private CCM_WD_Store $store;
    private CCM_WD_Settings $settings;
    private CCM_WD_Analyzer $analyzer;

    public function __construct( CCM_WD_Store $store, CCM_WD_Settings $settings, CCM_WD_Analyzer $analyzer ) {
        $this->store    = $store;
        $this->settings = $settings;
        $this->analyzer = $analyzer;
    }

    public function register_hooks(): void {
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 20, 2 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'track_order_outcome' ), 20, 4 );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validate_checkout( array $data, WP_Error $errors ): void {
        $settings = $this->settings->get();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        if ( $errors->has_errors() ) {
            return;
        }

        $client_ip = CCM_WD_Utils::get_client_ip();
        if ( $this->settings->is_ip_manually_blocked( $client_ip ) ) {
            $errors->add(
                'ccm_wd_manual_ip_blocked',
                apply_filters(
                    'ccm_wd_block_message',
                    __( 'Your transaction could not be processed. Please contact support if this is an error.', 'ccm-woo-defender' )
                )
            );

            $context = $this->build_context( $data );
            $this->store->add_event(
                array_merge(
                    $context,
                    array(
                        'ts'      => CCM_WD_Utils::now(),
                        'blocked' => true,
                        'score'   => 999,
                        'reasons' => 'manual_ip_block',
                    )
                )
            );

            return;
        }

        if ( $this->store->is_force_block_active() ) {
            $errors->add(
                'ccm_wd_force_blocked',
                apply_filters(
                    'ccm_wd_block_message',
                    __( 'Your transaction could not be processed. Please contact support if this is an error.', 'ccm-woo-defender' )
                )
            );

            $context = $this->build_context( $data );
            $this->store->add_event(
                array_merge(
                    $context,
                    array(
                        'ts'      => CCM_WD_Utils::now(),
                        'blocked' => true,
                        'score'   => 999,
                        'reasons' => 'force_block_active',
                    )
                )
            );

            return;
        }

        $context    = $this->build_context( $data );
        $evaluation = $this->analyzer->evaluate( $context );
        $is_blocked = ! empty( $evaluation['blocked'] );

        if ( $is_blocked ) {
            $errors->add(
                'ccm_wd_blocked',
                apply_filters(
                    'ccm_wd_block_message',
                    __( 'Your transaction could not be processed. Please contact support if this is an error.', 'ccm-woo-defender' )
                )
            );

            $default_duration = (int) $settings['block_duration_hours'] * HOUR_IN_SECONDS;
            $duration = (int) apply_filters( 'ccm_wd_block_duration', $default_duration, $context, $evaluation );
            $this->store->block_tokens( (array) ( $evaluation['matched_tokens'] ?? array() ), $duration );
        }

        $event = array_merge(
            $context,
            array(
                'ts'      => CCM_WD_Utils::now(),
                'blocked' => $is_blocked,
                'score'   => (int) ( $evaluation['score'] ?? 0 ),
                'reasons' => implode( ',', (array) ( $evaluation['reasons'] ?? array() ) ),
            )
        );

        $this->store->add_event( $event );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string|int|float|bool>
     */
    private function build_context( array $data ): array {
        $gateway = '';

        if ( function_exists( 'WC' ) && WC()->session ) {
            $gateway = (string) WC()->session->get( 'chosen_payment_method', '' );
        }

        if ( '' === $gateway ) {
            $gateway = CCM_WD_Utils::posted( $data, 'payment_method' );
        }

        $email    = CCM_WD_Utils::posted( $data, 'billing_email' );
        $name     = trim( CCM_WD_Utils::posted( $data, 'billing_first_name' ) . ' ' . CCM_WD_Utils::posted( $data, 'billing_last_name' ) );
        $address1 = CCM_WD_Utils::posted( $data, 'billing_address_1' );
        $city     = CCM_WD_Utils::posted( $data, 'billing_city' );
        $postcode = CCM_WD_Utils::posted( $data, 'billing_postcode' );
        $country  = CCM_WD_Utils::posted( $data, 'billing_country' );
        $ip       = CCM_WD_Utils::get_client_ip();
        $ua       = CCM_WD_Utils::get_user_agent();

        $total = 0.0;

        if ( function_exists( 'WC' ) && WC()->cart ) {
            $total = (float) WC()->cart->get_total( 'edit' );
        }

        $total_key = number_format( $total, 2, '.', '' );

        $payment_signature = CCM_WD_Utils::normalize_text( $gateway . '|' . $total_key . '|' . $country );
        $address_signature = CCM_WD_Utils::normalize_text( $address1 . '|' . $city . '|' . $postcode . '|' . $country );

        return array(
            'gateway'      => $gateway,
            'country'      => $country,
            'total'        => $total_key,
            'ip_hash'      => CCM_WD_Utils::hash_token( $ip ),
            'email_hash'   => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $email ) ),
            'name_hash'    => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $name ) ),
            'address_hash' => CCM_WD_Utils::hash_token( $address_signature ),
            'ua_hash'      => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $ua ) ),
            'payment_hash' => CCM_WD_Utils::hash_token( $payment_signature ),
            'address_fake' => CCM_WD_Utils::contains_fake_address_patterns( $address1, $city, $postcode ),
        );
    }

    public function track_order_outcome( int $order_id, string $old_status, string $new_status, WC_Order $order ): void {
        if ( ! in_array( $new_status, array( 'failed', 'cancelled' ), true ) ) {
            return;
        }

        $gateway = (string) $order->get_payment_method();
        $country = (string) $order->get_billing_country();
        $total   = number_format( (float) $order->get_total(), 2, '.', '' );

        $email    = (string) $order->get_billing_email();
        $name     = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
        $address1 = (string) $order->get_billing_address_1();
        $city     = (string) $order->get_billing_city();
        $postcode = (string) $order->get_billing_postcode();
        $ip       = (string) $order->get_customer_ip_address();
        $ua       = (string) $order->get_customer_user_agent();

        $payment_signature = CCM_WD_Utils::normalize_text( $gateway . '|' . $total . '|' . $country );
        $address_signature = CCM_WD_Utils::normalize_text( $address1 . '|' . $city . '|' . $postcode . '|' . $country );

        $event = array(
            'ts'           => CCM_WD_Utils::now(),
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
            'blocked'      => false,
            'score'        => 10,
            'reasons'      => 'order_' . $new_status,
        );

        $this->store->add_event( $event );
    }
}
