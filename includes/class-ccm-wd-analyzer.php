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
        $effective       = $this->settings->get_effective_detection_settings();
        $score           = 0;
        $reasons         = array();

        // Block tokens: identifiers stored in the block list when a visitor is
        // blocked, and checked on future attempts for `matched_existing_block`.
        // ua_hash is deliberately EXCLUDED — User-Agent strings are shared by
        // millions of users (same browser + OS), so blocking on ua_hash causes
        // widespread false positives when one fraudster poisons the token.
        // ua_hash is still used for the `same_device_multi_identity` scoring
        // signal via build_metrics().
        $matching_tokens = array(
            $context['ip_hash'] ?? '',
            $context['email_hash'] ?? '',
            $context['address_hash'] ?? '',
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
            $score    += (int) $effective['weight_suspicious_address'];
            $reasons[] = 'suspicious_address';
        }

        if ( $metrics['same_payment_attempts'] >= (int) $effective['payment_identity_min_attempts'] && $metrics['same_payment_unique_emails'] >= (int) $effective['payment_identity_min_unique_emails'] ) {
            $score    += (int) $effective['weight_payment_identity_churn'];
            $reasons[] = 'reused_gateway_amount_identity_churn';
        }

        if ( $metrics['same_ip_attempts'] >= (int) $effective['ip_identity_min_attempts'] && $metrics['same_ip_unique_addresses'] >= (int) $effective['ip_identity_min_unique_addresses'] ) {
            $score    += (int) $effective['weight_ip_identity_churn'];
            $reasons[] = 'same_ip_multi_identity';
        }

        if ( $metrics['same_ua_attempts'] >= (int) $effective['device_identity_min_attempts'] && $metrics['same_ua_unique_emails'] >= (int) $effective['device_identity_min_unique_emails'] ) {
            $score    += (int) $effective['weight_device_identity_churn'];
            $reasons[] = 'same_device_multi_identity';
        }

        if ( $metrics['blocked_attempts_recent'] >= (int) $effective['repeat_after_blocks_min_attempts'] ) {
            $score    += (int) $effective['weight_repeat_after_blocks'];
            $reasons[] = 'repeat_after_blocks';
        }

        $threshold = (int) apply_filters( 'ccm_wd_block_threshold', (int) $effective['threshold'] );
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

        $ctx_ip      = (string) ( $context['ip_hash'] ?? '' );
        $ctx_email   = (string) ( $context['email_hash'] ?? '' );
        $ctx_ua      = (string) ( $context['ua_hash'] ?? '' );

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

            // Only count blocks related to the current visitor (matched by IP, email, or UA).
            if ( $event_blocked ) {
                $matches_visitor = false;

                if ( $ctx_ip !== '' && $event_ip === $ctx_ip ) {
                    $matches_visitor = true;
                } elseif ( $ctx_email !== '' && $event_email === $ctx_email ) {
                    $matches_visitor = true;
                } elseif ( $ctx_ua !== '' && $event_ua === $ctx_ua ) {
                    $matches_visitor = true;
                }

                if ( $matches_visitor ) {
                    ++$blocked_attempts_recent;
                }
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
