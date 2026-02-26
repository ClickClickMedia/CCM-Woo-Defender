<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Analyzer {
    private CCM_WD_Store $store;
    private CCM_WD_Settings $settings;

    public function __construct( CCM_WD_Store $store, CCM_WD_Settings $settings ) {
        $this->store = $store;
        $this->settings = $settings;
    }

    /**
     * @param array<string, string|int|float|bool> $context
     * @return array<string, mixed>
     */
    public function evaluate( array $context ): array {
        $settings        = $this->settings->get();
        $score           = 0;
        $reasons         = array();
        $matching_tokens = array(
            $context['ip_hash'] ?? '',
            $context['email_hash'] ?? '',
            $context['address_hash'] ?? '',
            $context['ua_hash'] ?? '',
            $context['payment_hash'] ?? '',
        );

        foreach ( $matching_tokens as $token ) {
            if ( is_string( $token ) && '' !== $token && $this->store->is_blocked_token( $token ) ) {
                $score    += 100;
                $reasons[] = 'matched_existing_block';
                break;
            }
        }

        $lookback_seconds = (int) $settings['lookback_hours'] * HOUR_IN_SECONDS;
        $events_lookback  = $this->store->get_recent_events( $lookback_seconds );
        $metrics          = $this->build_metrics( $events_lookback, $context );

        if ( ! empty( $context['address_fake'] ) ) {
            $score    += (int) $settings['weight_suspicious_address'];
            $reasons[] = 'suspicious_address';
        }

        if ( $metrics['same_payment_attempts'] >= (int) $settings['payment_identity_min_attempts'] && $metrics['same_payment_unique_emails'] >= (int) $settings['payment_identity_min_unique_emails'] ) {
            $score    += (int) $settings['weight_payment_identity_churn'];
            $reasons[] = 'reused_gateway_amount_identity_churn';
        }

        if ( $metrics['same_ip_attempts'] >= (int) $settings['ip_identity_min_attempts'] && $metrics['same_ip_unique_addresses'] >= (int) $settings['ip_identity_min_unique_addresses'] ) {
            $score    += (int) $settings['weight_ip_identity_churn'];
            $reasons[] = 'same_ip_multi_identity';
        }

        if ( $metrics['same_ua_attempts'] >= (int) $settings['device_identity_min_attempts'] && $metrics['same_ua_unique_emails'] >= (int) $settings['device_identity_min_unique_emails'] ) {
            $score    += (int) $settings['weight_device_identity_churn'];
            $reasons[] = 'same_device_multi_identity';
        }

        if ( $metrics['blocked_attempts_recent'] >= (int) $settings['repeat_after_blocks_min_attempts'] ) {
            $score    += (int) $settings['weight_repeat_after_blocks'];
            $reasons[] = 'repeat_after_blocks';
        }

        $threshold = (int) apply_filters( 'ccm_wd_block_threshold', (int) $settings['threshold'] );
        $blocked   = $score >= $threshold;

        return array(
            'blocked'        => $blocked,
            'score'          => $score,
            'threshold'      => $threshold,
            'reasons'        => array_values( array_unique( $reasons ) ),
            'metrics'        => $metrics,
            'matched_tokens' => array_values( array_filter( $matching_tokens ) ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @param array<string, string|int|float|bool> $context
     * @return array<string, int>
     */
    private function build_metrics( array $events, array $context ): array {
        $same_ip_attempts            = 0;
        $same_ip_unique_addresses    = array();
        $same_payment_attempts       = 0;
        $same_payment_unique_emails  = array();
        $same_ua_attempts            = 0;
        $same_ua_unique_emails       = array();
        $blocked_attempts_recent     = 0;

        foreach ( $events as $event ) {
            $event_ip      = (string) ( $event['ip_hash'] ?? '' );
            $event_addr    = (string) ( $event['address_hash'] ?? '' );
            $event_payment = (string) ( $event['payment_hash'] ?? '' );
            $event_email   = (string) ( $event['email_hash'] ?? '' );
            $event_ua      = (string) ( $event['ua_hash'] ?? '' );
            $event_blocked = ! empty( $event['blocked'] );

            if ( $event_ip !== '' && $event_ip === (string) ( $context['ip_hash'] ?? '' ) ) {
                ++$same_ip_attempts;
                if ( '' !== $event_addr ) {
                    $same_ip_unique_addresses[ $event_addr ] = true;
                }
            }

            if ( $event_payment !== '' && $event_payment === (string) ( $context['payment_hash'] ?? '' ) ) {
                ++$same_payment_attempts;
                if ( '' !== $event_email ) {
                    $same_payment_unique_emails[ $event_email ] = true;
                }
            }

            if ( $event_ua !== '' && $event_ua === (string) ( $context['ua_hash'] ?? '' ) ) {
                ++$same_ua_attempts;
                if ( '' !== $event_email ) {
                    $same_ua_unique_emails[ $event_email ] = true;
                }
            }

            if ( $event_blocked ) {
                ++$blocked_attempts_recent;
            }
        }

        return array(
            'same_ip_attempts'            => $same_ip_attempts,
            'same_ip_unique_addresses'    => count( $same_ip_unique_addresses ),
            'same_payment_attempts'       => $same_payment_attempts,
            'same_payment_unique_emails'  => count( $same_payment_unique_emails ),
            'same_ua_attempts'            => $same_ua_attempts,
            'same_ua_unique_emails'       => count( $same_ua_unique_emails ),
            'blocked_attempts_recent'     => $blocked_attempts_recent,
        );
    }
}
