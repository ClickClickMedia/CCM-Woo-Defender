<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Analyzer {
    private CCM_WD_Store $store;

    public function __construct( CCM_WD_Store $store ) {
        $this->store = $store;
    }

    /**
     * @param array<string, string|int|float|bool> $context
     * @return array<string, mixed>
     */
    public function evaluate( array $context ): array {
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

        $events_24h = $this->store->get_recent_events( DAY_IN_SECONDS );
        $metrics    = $this->build_metrics( $events_24h, $context );

        if ( ! empty( $context['address_fake'] ) ) {
            $score    += 20;
            $reasons[] = 'suspicious_address';
        }

        if ( $metrics['same_payment_attempts'] >= 4 && $metrics['same_payment_unique_emails'] >= 3 ) {
            $score    += 45;
            $reasons[] = 'reused_gateway_amount_identity_churn';
        }

        if ( $metrics['same_ip_attempts'] >= 5 && $metrics['same_ip_unique_addresses'] >= 3 ) {
            $score    += 40;
            $reasons[] = 'same_ip_multi_identity';
        }

        if ( $metrics['same_ua_attempts'] >= 7 && $metrics['same_ua_unique_emails'] >= 4 ) {
            $score    += 30;
            $reasons[] = 'same_device_multi_identity';
        }

        if ( $metrics['blocked_attempts_recent'] >= 2 ) {
            $score    += 30;
            $reasons[] = 'repeat_after_blocks';
        }

        $threshold = (int) apply_filters( 'ccm_wd_block_threshold', 70 );
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
