<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Checkout_Guard {
    private CCM_WD_Store $store;
    private CCM_WD_Settings $settings;
    private CCM_WD_Analyzer $analyzer;
    private bool $request_already_blocked = false;
    private ?CCM_WD_GeoIP $geoip = null;

    public function __construct( CCM_WD_Store $store, CCM_WD_Settings $settings, CCM_WD_Analyzer $analyzer ) {
        $this->store    = $store;
        $this->settings = $settings;
        $this->analyzer = $analyzer;
    }

    public function register_hooks(): void {
        // Classic (shortcode) checkout hooks.
        add_action( 'woocommerce_checkout_process', array( $this, 'checkout_process_guard' ), 5 );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 20, 2 );

        // Backfill order_id on the event recorded during validate_checkout (classic flow).
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'backfill_order_id' ), 5, 1 );

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

        // Whitelisted IPs always pass through.
        if ( $this->settings->is_ip_whitelisted( $client_ip ) ) {
            return;
        }

        if ( $this->settings->is_ip_manually_blocked( $client_ip ) ) {
            wc_add_notice(
                (string) apply_filters(
                    'ccm_wd_block_message',
                    __( 'Your transaction could not be processed. Please contact support if this is an error.', 'ccm-woo-defender' )
                ),
                'error'
            );

            $this->request_already_blocked = true;
            return;
        }
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

        // Whitelisted IPs always pass through without scoring.
        if ( $this->settings->is_ip_whitelisted( $client_ip ) ) {
            return;
        }

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
                        'ts'            => CCM_WD_Utils::now(),
                        'order_id'      => 0,
                        'blocked'       => true,
                        'score'         => 999,
                        'reasons'       => 'manual_ip_block',
                        'geoip_country' => '',
                    )
                )
            );

            return;
        }

        // --- GeoIP country check (classic checkout) ---
        $client_ip  = CCM_WD_Utils::get_client_ip();
        $geo_result = $this->check_geoip_country( $client_ip );

        if ( $geo_result['blocked'] && 'block' === $geo_result['action'] ) {
            $errors->add(
                'ccm_wd_country_blocked',
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
                        'ts'            => CCM_WD_Utils::now(),
                        'order_id'      => 0,
                        'blocked'       => true,
                        'score'         => 999,
                        'reasons'       => 'geoip_country_block:' . $geo_result['country'],
                        'geoip_country' => $geo_result['country'],
                    )
                )
            );

            return;
        }

        $context    = $this->build_context( $data );
        $evaluation = $this->analyzer->evaluate( $context );

        // Add GeoIP risk score if country is flagged but action is 'score' (not hard block).
        if ( $geo_result['blocked'] && 'score' === $geo_result['action'] ) {
            $geo_weight = (int) ( $settings['geoip_weight'] ?? 80 );
            $evaluation['score']    = (int) $evaluation['score'] + $geo_weight;
            $evaluation['reasons']  = array_merge( (array) $evaluation['reasons'], array( 'geoip_country_score:' . $geo_result['country'] ) );
            $evaluation['blocked']  = $evaluation['score'] >= (int) $evaluation['threshold'];
        }

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
                'ts'            => CCM_WD_Utils::now(),
                'order_id'      => 0,
                'blocked'       => $is_blocked,
                'score'         => (int) ( $evaluation['score'] ?? 0 ),
                'reasons'       => implode( ',', (array) ( $evaluation['reasons'] ?? array() ) ),
                'geoip_country' => $geo_result['country'],
            )
        );

        $this->store->add_event( $event );
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

        // --- Whitelisted IPs always pass through ---
        if ( $this->settings->is_ip_whitelisted( $client_ip ) ) {
            return;
        }

        // --- Manual IP block ---
        if ( $this->settings->is_ip_manually_blocked( $client_ip ) ) {
            $context = $this->build_context_from_order( $order );
            $this->store->add_event(
                array_merge(
                    $context,
                    array(
                        'ts'            => CCM_WD_Utils::now(),
                        'order_id'      => $order->get_id(),
                        'blocked'       => true,
                        'score'         => 999,
                        'reasons'       => 'manual_ip_block',
                        'geoip_country' => '',
                    )
                )
            );

            $this->throw_store_api_error( 'ccm_wd_manual_ip_blocked', $block_message );
        }

        // --- GeoIP country check ---
        $geo_result = $this->check_geoip_country( $client_ip );

        if ( $geo_result['blocked'] && 'block' === $geo_result['action'] ) {
            $context = $this->build_context_from_order( $order );
            $this->store->add_event(
                array_merge(
                    $context,
                    array(
                        'ts'            => CCM_WD_Utils::now(),
                        'order_id'      => $order->get_id(),
                        'blocked'       => true,
                        'score'         => 999,
                        'reasons'       => 'geoip_country_block:' . $geo_result['country'],
                        'geoip_country' => $geo_result['country'],
                    )
                )
            );

            $this->throw_store_api_error( 'ccm_wd_country_blocked', $block_message );
        }

        // --- Risk-score evaluation ---
        $context    = $this->build_context_from_order( $order );
        $evaluation = $this->analyzer->evaluate( $context );

        // Add GeoIP risk score if country is flagged but action is 'score' (not hard block).
        if ( $geo_result['blocked'] && 'score' === $geo_result['action'] ) {
            $geo_weight = (int) ( $settings['geoip_weight'] ?? 80 );
            $evaluation['score']    = (int) $evaluation['score'] + $geo_weight;
            $evaluation['reasons']  = array_merge( (array) $evaluation['reasons'], array( 'geoip_country_score:' . $geo_result['country'] ) );
            $evaluation['blocked']  = $evaluation['score'] >= (int) $evaluation['threshold'];
        }

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
                    'ts'            => CCM_WD_Utils::now(),
                    'order_id'      => $order->get_id(),
                    'blocked'       => $is_blocked,
                    'score'         => (int) ( $evaluation['score'] ?? 0 ),
                    'reasons'       => implode( ',', (array) ( $evaluation['reasons'] ?? array() ) ),
                    'geoip_country' => $geo_result['country'],
                )
            )
        );

        if ( $is_blocked ) {
            $this->throw_store_api_error( 'ccm_wd_blocked', $block_message );
        }
    }

    /**
     * Get / lazily create the GeoIP instance.
     */
    private function get_geoip(): ?CCM_WD_GeoIP {
        if ( null !== $this->geoip ) {
            return $this->geoip->has_credentials() ? $this->geoip : null;
        }

        $s = $this->settings->get();

        if ( empty( $s['geoip_enabled'] ) ) {
            return null;
        }

        $account_id  = (string) ( $s['geoip_account_id'] ?? '' );
        $license_key = (string) ( $s['geoip_license_key'] ?? '' );

        $this->geoip = new CCM_WD_GeoIP( $account_id, $license_key );

        return $this->geoip->has_credentials() ? $this->geoip : null;
    }

    /**
     * Check GeoIP country and return the resolved country code.
     * Returns an array with 'country' and 'blocked' keys.
     *
     * @return array{country: string, blocked: bool, action: string}
     */
    private function check_geoip_country( string $client_ip ): array {
        $result = array( 'country' => '', 'blocked' => false, 'action' => '' );

        $geoip = $this->get_geoip();

        if ( null === $geoip ) {
            return $result;
        }

        $country = $geoip->get_country( $client_ip );
        $result['country'] = $country;

        if ( '' === $country ) {
            return $result;
        }

        $settings = $this->settings->get();

        if ( ! empty( $settings['geoip_log_only'] ) ) {
            return $result;
        }

        if ( $this->settings->is_country_blocked( $country ) ) {
            $result['blocked'] = true;
            $result['action']  = (string) ( $settings['geoip_action'] ?? 'block' );
        }

        return $result;
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
            'client_ip'    => $ip,
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
            'client_ip'    => $ip,
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
     * Backfill order_id on the event recorded during validate_checkout().
     * Fires after WC creates the order in the classic checkout flow.
     */
    public function backfill_order_id( int $order_id ): void {
        $client_ip = CCM_WD_Utils::get_client_ip();
        $this->store->backfill_last_event_order_id( $order_id, $client_ip );
    }

    public function track_order_outcome( int $order_id, string $old_status, string $new_status, WC_Order $order ): void {
        if ( ! in_array( $new_status, array( 'failed', 'cancelled' ), true ) ) {
            return;
        }

        // ── Gateway fraud detection (runs before duplicate check). ──
        if ( 'failed' === $new_status && $this->detect_gateway_fraud( $order ) ) {
            $this->handle_gateway_fraud( $order_id, $order );
            return;
        }

        // ── Duplicate prevention. ──
        // If we already recorded an event for this order (checkout guard
        // or a previous outcome call), skip to avoid duplicate rows and
        // the "blocked + allowed" inconsistency on Store API orders.
        if ( $this->store->has_event_for_order( $order_id ) ) {
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

        // GeoIP lookup on the order's stored IP.
        $geo_country = '';
        if ( '' !== $ip ) {
            $geo_result  = $this->check_geoip_country( $ip );
            $geo_country = $geo_result['country'];
        }

        $payment_signature = CCM_WD_Utils::normalize_text( $gateway . '|' . $total . '|' . $country );
        $address_signature = CCM_WD_Utils::normalize_text( $address1 . '|' . $city . '|' . $postcode . '|' . $country );

        $event = array(
            'ts'            => CCM_WD_Utils::now(),
            'order_id'      => $order_id,
            'gateway'       => $gateway,
            'country'       => $country,
            'total'         => $total,
            'client_ip'     => $ip,
            'ip_hash'       => CCM_WD_Utils::hash_token( $ip ),
            'email_hash'    => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $email ) ),
            'name_hash'     => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $name ) ),
            'address_hash'  => CCM_WD_Utils::hash_token( $address_signature ),
            'ua_hash'       => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $ua ) ),
            'payment_hash'  => CCM_WD_Utils::hash_token( $payment_signature ),
            'address_fake'  => CCM_WD_Utils::contains_fake_address_patterns( $address1, $city, $postcode ),
            'blocked'       => false,
            'score'         => 10,
            'reasons'       => 'order_' . $new_status,
            'geoip_country' => $geo_country,
        );

        $this->store->add_event( $event );
    }

    /* ────────────────────────────────────────────────────────────
     *  Gateway fraud auto-detection
     * ──────────────────────────────────────────────────────────── */

    /**
     * Scan order notes for fraud-related keywords added by the payment gateway.
     *
     * @return bool True when at least one note matches a fraud pattern.
     */
    private function detect_gateway_fraud( WC_Order $order ): bool {
        if ( ! function_exists( 'wc_get_order_notes' ) ) {
            return false;
        }

        $notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

        /** @var string[] $patterns Filterable list of lowercase substrings that indicate gateway fraud. */
        $patterns = (array) apply_filters( 'ccm_wd_fraud_patterns', array( 'fraud', 'risk_threshold' ) );

        foreach ( $notes as $note ) {
            $content = strtolower( (string) $note->content );

            foreach ( $patterns as $pattern ) {
                if ( false !== strpos( $content, (string) $pattern ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Block the customer IP and record a single BLOCKED event for gateway fraud.
     */
    private function handle_gateway_fraud( int $order_id, WC_Order $order ): void {
        $ip       = (string) $order->get_customer_ip_address();
        $settings = $this->settings->get();

        // Always (re-)block the IP — extends any existing block.
        if ( '' !== $ip ) {
            $duration = (int) ( $settings['block_duration_hours'] ?? 24 ) * HOUR_IN_SECONDS;
            $this->store->block_tokens( array( CCM_WD_Utils::hash_token( $ip ) ), $duration );
        }

        // Record only one fraud event per order.
        if ( $this->store->has_blocked_event_for_order( $order_id ) ) {
            return;
        }

        // GeoIP lookup.
        $geo_country = '';
        if ( '' !== $ip ) {
            $geo_result  = $this->check_geoip_country( $ip );
            $geo_country = $geo_result['country'];
        }

        $gateway  = (string) $order->get_payment_method();
        $country  = (string) $order->get_billing_country();
        $total    = number_format( (float) $order->get_total(), 2, '.', '' );
        $email    = (string) $order->get_billing_email();
        $name     = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
        $address1 = (string) $order->get_billing_address_1();
        $city     = (string) $order->get_billing_city();
        $postcode = (string) $order->get_billing_postcode();
        $ua       = (string) $order->get_customer_user_agent();

        $payment_signature = CCM_WD_Utils::normalize_text( $gateway . '|' . $total . '|' . $country );
        $address_signature = CCM_WD_Utils::normalize_text( $address1 . '|' . $city . '|' . $postcode . '|' . $country );

        $this->store->add_event( array(
            'ts'            => CCM_WD_Utils::now(),
            'order_id'      => $order_id,
            'gateway'       => $gateway,
            'country'       => $country,
            'total'         => $total,
            'client_ip'     => $ip,
            'ip_hash'       => CCM_WD_Utils::hash_token( $ip ),
            'email_hash'    => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $email ) ),
            'name_hash'     => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $name ) ),
            'address_hash'  => CCM_WD_Utils::hash_token( $address_signature ),
            'ua_hash'       => CCM_WD_Utils::hash_token( CCM_WD_Utils::normalize_text( $ua ) ),
            'payment_hash'  => CCM_WD_Utils::hash_token( $payment_signature ),
            'address_fake'  => CCM_WD_Utils::contains_fake_address_patterns( $address1, $city, $postcode ),
            'blocked'       => true,
            'score'         => 999,
            'reasons'       => 'gateway_fraud',
            'geoip_country' => $geo_country,
        ) );
    }
}
