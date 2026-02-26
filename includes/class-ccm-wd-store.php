<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Store {
    private const OPTION_EVENTS = 'ccm_wd_events';
    private const OPTION_BLOCKS = 'ccm_wd_blocks';
    private const OPTION_FORCE_BLOCK_UNTIL = 'ccm_wd_force_block_until';
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
     * @param array<string, mixed> $event
     */
    public function add_event( array $event ): void {
        $events   = $this->get_events();
        $events[] = $event;
        $events   = $this->prune_events( $events );

        update_option( self::OPTION_EVENTS, $events, false );
    }

    /**
     * @return array<string, int>
     */
    public function get_blocks(): array {
        $blocks = get_option( self::OPTION_BLOCKS, array() );

        if ( ! is_array( $blocks ) ) {
            $blocks = array();
        }

        return $this->prune_blocks( $blocks );
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

        update_option( self::OPTION_BLOCKS, $this->prune_blocks( $blocks ), false );
    }

    public function clear_blocks(): void {
        update_option( self::OPTION_BLOCKS, array(), false );
    }

    public function clear_events(): void {
        update_option( self::OPTION_EVENTS, array(), false );
    }

    public function set_force_block( int $duration_seconds ): int {
        $duration = max( 60, $duration_seconds );
        $until    = CCM_WD_Utils::now() + $duration;
        update_option( self::OPTION_FORCE_BLOCK_UNTIL, $until, false );
        return $until;
    }

    public function clear_force_block(): void {
        delete_option( self::OPTION_FORCE_BLOCK_UNTIL );
    }

    public function is_force_block_active(): bool {
        $until = (int) get_option( self::OPTION_FORCE_BLOCK_UNTIL, 0 );

        if ( $until <= 0 ) {
            return false;
        }

        if ( $until < CCM_WD_Utils::now() ) {
            delete_option( self::OPTION_FORCE_BLOCK_UNTIL );
            return false;
        }

        return true;
    }

    public function get_force_block_until(): int {
        $until = (int) get_option( self::OPTION_FORCE_BLOCK_UNTIL, 0 );
        return $until > 0 ? $until : 0;
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
            'force_block_on' => $this->is_force_block_active() ? 1 : 0,
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
    private function prune_blocks( array $blocks ): array {
        $now = CCM_WD_Utils::now();

        foreach ( $blocks as $token => $expires ) {
            if ( (int) $expires < $now ) {
                unset( $blocks[ $token ] );
            }
        }

        update_option( self::OPTION_BLOCKS, $blocks, false );

        return $blocks;
    }
}
