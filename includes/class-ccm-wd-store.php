<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Store {
    private const OPTION_EVENTS = 'ccm_wd_events';
    private const OPTION_BLOCKS = 'ccm_wd_blocks';
    private const MAX_EVENTS    = 2500;
    private const RETENTION_SEC = 2592000;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_recent_events( int $lookback_seconds ): array {
        $events     = $this->get_events();
        $cutoff     = CCM_WD_Utils::now() - $lookback_seconds;
        $filtered   = array();

        foreach ( $events as $event ) {
            if ( ! isset( $event['ts'] ) ) {
                continue;
            }

            if ( (int) $event['ts'] >= $cutoff ) {
                $filtered[] = $event;
            }
        }

        return $filtered;
    }

    /**
     * Return all stored events in reverse chronological order (newest first).
     *
     * @param int $limit Maximum number of events to return. 0 = all.
     * @return array<int, array<string, mixed>>
     */
    public function get_history_events( int $limit = 0 ): array {
        $events = $this->get_events();
        $events = array_reverse( $events );

        if ( $limit > 0 ) {
            $events = array_slice( $events, 0, $limit );
        }

        return $events;
    }

    /**
     * Return the total number of stored events.
     */
    public function get_events_count(): int {
        return count( $this->get_events() );
    }

    /**
     * @param array<string, mixed> $event
     */
    public function add_event( array $event ): void {
        $events   = $this->get_events();
        $events[] = $event;
        $events   = $this->prune_events( $events );

        update_option( self::OPTION_EVENTS, $events, false );
    }

    /**
     * Backfill `order_id` on the most recent event that has order_id = 0
     * and matches the given IP address (to avoid mis-attribution).
     */
    public function backfill_last_event_order_id( int $order_id, string $client_ip ): void {
        $events = $this->get_events();

        // Walk backwards to find the most recent matching event.
        for ( $i = count( $events ) - 1; $i >= 0; $i-- ) {
            $event = $events[ $i ];

            if ( (int) ( $event['order_id'] ?? -1 ) !== 0 ) {
                continue;
            }

            if ( '' !== $client_ip && ( $event['client_ip'] ?? '' ) !== $client_ip ) {
                continue;
            }

            $events[ $i ]['order_id'] = $order_id;
            update_option( self::OPTION_EVENTS, $events, false );
            return;
        }
    }

    /**
     * @return array<string, int>
     */
    public function get_blocks(): array {
        $blocks = get_option( self::OPTION_BLOCKS, array() );

        if ( ! is_array( $blocks ) ) {
            $blocks = array();
        }

        return $this->prune_blocks( $blocks, false );
    }

    public function is_blocked_token( string $token ): bool {
        if ( '' === $token ) {
            return false;
        }

        $blocks = $this->get_blocks();
        return isset( $blocks[ $token ] ) && (int) $blocks[ $token ] >= CCM_WD_Utils::now();
    }

    /**
     * @param array<int, string> $tokens
     */
    public function block_tokens( array $tokens, int $duration_seconds ): void {
        $duration = max( 300, $duration_seconds );
        $expires  = CCM_WD_Utils::now() + $duration;
        $blocks   = $this->get_blocks();

        foreach ( $tokens as $token ) {
            if ( '' === $token ) {
                continue;
            }

            $blocks[ $token ] = $expires;
        }

        update_option( self::OPTION_BLOCKS, $this->prune_blocks( $blocks, true ), false );
    }

    public function clear_blocks(): void {
        update_option( self::OPTION_BLOCKS, array(), false );
    }

    public function clear_events(): void {
        update_option( self::OPTION_EVENTS, array(), false );
    }

    /**
     * @return array<string, int>
     */
    public function get_stats(): array {
        $events        = $this->get_events();
        $blocked_count = 0;

        foreach ( $events as $event ) {
            if ( ! empty( $event['blocked'] ) ) {
                ++$blocked_count;
            }
        }

        return array(
            'events_total'   => count( $events ),
            'events_blocked' => $blocked_count,
            'active_blocks'  => count( $this->get_blocks() ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get_events(): array {
        $events = get_option( self::OPTION_EVENTS, array() );

        if ( ! is_array( $events ) ) {
            return array();
        }

        return $events;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function prune_events( array $events ): array {
        $cutoff = CCM_WD_Utils::now() - self::RETENTION_SEC;
        $events = array_values(
            array_filter(
                $events,
                static function ( $event ) use ( $cutoff ): bool {
                    return isset( $event['ts'] ) && (int) $event['ts'] >= $cutoff;
                }
            )
        );

        if ( count( $events ) > self::MAX_EVENTS ) {
            $events = array_slice( $events, -1 * self::MAX_EVENTS );
        }

        return $events;
    }

    /**
     * @param array<string, int> $blocks
     * @return array<string, int>
     */
    private function prune_blocks( array $blocks, bool $persist = false ): array {
        $now = CCM_WD_Utils::now();

        foreach ( $blocks as $token => $expires ) {
            if ( (int) $expires < $now ) {
                unset( $blocks[ $token ] );
            }
        }

        if ( $persist ) {
            update_option( self::OPTION_BLOCKS, $blocks, false );
        }

        return $blocks;
    }
}
