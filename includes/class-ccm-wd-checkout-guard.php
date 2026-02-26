<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Checkout_Guard {
    private CCM_WD_Store $store;
    private CCM_WD_Settings $settings;
    private CCM_WD_Analyzer $analyzer;
    private bool $request_already_blocked = false;

    public function __construct( CCM_WD_Store $store, CCM_WD_Settings $settings, CCM_WD_Analyzer $analyzer ) {
        $this->store    = $store;
        $this->settings = $settings;
        $this->analyzer = $analyzer;
    }

    public function register_hooks(): void {
        // Classic (shortcode) checkout hooks.
        add_action( 'woocommerce_checkout_process', array( $this, 'checkout_process_guard' ), 5 );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 20, 2 );

        // Block-based (Store API) checkout hook — fires for /wc/store/v1/checkout.
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'store_api_checkout_guard' ), 5, 2 );

        // Order outcome tracking (both flows).
        add_action( 'woocommerce_order_status_changed', array( $this, 'track_order_outcome' ), 20, 4 );
    }

    public function checkout_process_guard(): void {
        $settings = $this->settings->get();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        $client_ip = CCM_WD_Utils::get_client_ip();

        if ( $this->settings->is_ip_manually_blocked( $client_ip ) ) {
            wc_add_notice(
                (string) apply_filters(
                    'ccm_wd_block_message',
                    __( 'Your transaction could not be processed. Please contact support if this is an error.', 'ccm-woo-defender' )
                ),
                'error'
            );

            $this->store->set_last_request_context(
                array(
                    'hook'    => 'woocommerce_checkout_process',
                    'blocked' => true,
                    'reason'  => 'manual_ip_block',
                )
            );

            $this->request_already_blocked = true;
            return;
        }

        if ( $this->store->is_force_block_active() ) {
            wc_add_notice(
                (string) apply_filters(
                    'ccm_wd_block_message',
                    __( 'Your transaction could not be processed. Please contact support if this is an error.', 'ccm-woo-defender' )
                ),
                'error'
            );

            $this->store->set_last_request_context(
                array(
                    'hook'    => 'woocommerce_checkout_process',
                    'blocked' => true,
                    'reason'  => 'force_block_active',
                )
            );

            $this->request_already_blocked = true;
            return;
        }

        $this->store->set_last_request_context(
            array(
                'hook'    => 'woocommerce_checkout_process',
                'blocked' => false,
                'reason'  => 'none',
            )
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validate_checkout( array $data, WP_Error $errors ): void {
        $settings = $this->settings->get();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        if ( $this->request_already_blocked ) {
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

            $this->store->set_last_request_context(
                array(
                    'hook'    => 'woocommerce_after_checkout_validation',
                    'blocked' => true,
                    'reason'  => 'manual_ip_block',
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

            $this->store->set_last_request_context(
                array(
                    'hook'    => 'woocommerce_after_checkout_validation',
                    'blocked' => true,
                    'reason'  => 'force_block_active',
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

        $this->store->set_last_request_context(
            array(
                'hook'    => 'woocommerce_after_checkout_validation',
                'blocked' => $is_blocked,
                'reason'  => $is_blocked ? 'risk_score_block' : 'not_blocked',
            )
        );
    }

    /**
     * Guard for WooCommerce Block-based (Store API) checkout.
     *
     * Fires on POST /wc/store/v1/checkout. To reject the order we throw
     * a RouteException — the Store API translates that into a JSON error
     * visible in the block checkout UI.
     */
    public function store_api_checkout_guard( \WC_Order $order, \WP_REST_Request $request ): void {
        $settings = $this->settings->get();

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        $block_message = (string) apply_filters(
            'ccm_wd_block_message',
            __( 'Your transaction could not be processed. Please contact support if this is an error.', 'ccm-woo-defender' )
        );

        $client_ip = CCM_WD_Utils::get_client_ip();

        // --- Manual IP block ---
        if ( $this->settings->is_ip_manually_blocked( $client_ip ) ) {
            $this->store->set_last_request_context(
                array(
                    'hook'    => 'store_api_checkout',
                    'blocked' => true,
                    'reason'  => 'manual_ip_block',
                )
            );

            $context = $this->build_context_from_order( $order );
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

            $this->throw_store_api_error( 'ccm_wd_manual_ip_blocked', $block_message );
        }

        // --- Force-block mode ---
        if ( $this->store->is_force_block_active() ) {
            $this->store->set_last_request_context(
                array(
                    'hook'    => 'store_api_checkout',
                    'blocked' => true,
                    'reason'  => 'force_block_active',
                )
            );

            $context = $this->build_context_from_order( $order );
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

            $this->throw_store_api_error( 'ccm_wd_force_blocked', $block_message );
        }

        // --- Risk-score evaluation ---
        $context    = $this->build_context_from_order( $order );
        $evaluation = $this->analyzer->evaluate( $context );
        $is_blocked = ! empty( $evaluation['blocked'] );

        if ( $is_blocked ) {
            $default_duration = (int) $settings['block_duration_hours'] * HOUR_IN_SECONDS;
            $duration = (int) apply_filters( 'ccm_wd_block_duration', $default_duration, $context, $evaluation );
            $this->store->block_tokens( (array) ( $evaluation['matched_tokens'] ?? array() ), $duration );
        }

        $this->store->add_event(
            array_merge(
                $context,
                array(
                    'ts'      => CCM_WD_Utils::now(),
                    'blocked' => $is_blocked,
                    'score'   => (int) ( $evaluation['score'] ?? 0 ),
                    'reasons' => implode( ',', (array) ( $evaluation['reasons'] ?? array() ) ),
                )
            )
        );

        $this->store->set_last_request_context(
            array(
                'hook'    => 'store_api_checkout',
                'blocked' => $is_blocked,
                'reason'  => $is_blocked ? 'risk_score_block' : 'not_blocked',
            )
        );

        if ( $is_blocked ) {
            $this->throw_store_api_error( 'ccm_wd_blocked', $block_message );
        }
    }

    /**
     * Throw a RouteException that the WooCommerce Store API
     * translates into a checkout error visible to the customer.
     *
     * @throws \Exception Always throws.
     */
    private function throw_store_api_error( string $code, string $message ): void {
        if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( $code, $message, 403 );
        }

        // Fallback for older WC versions where the class may not exist.
        throw new \Exception( esc_html( $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    /**
     * Build a scoring context from a WC_Order object (used by Store API flow
     * where posted form data is not available — everything is on the order).
     *
     * @return array<string, string|int|float|bool>
     */
    private function build_context_from_order( \WC_Order $order ): array {
        $gateway  = (string) $order->get_payment_method();
        $country  = (string) $order->get_billing_country();
        $total    = number_format( (float) $order->get_total(), 2, '.', '' );
        $email    = (string) $order->get_billing_email();
        $name     = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
        $address1 = (string) $order->get_billing_address_1();
        $city     = (string) $order->get_billing_city();
        $postcode = (string) $order->get_billing_postcode();
        $ip       = CCM_WD_Utils::get_client_ip();
        $ua       = CCM_WD_Utils::get_user_agent();

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
