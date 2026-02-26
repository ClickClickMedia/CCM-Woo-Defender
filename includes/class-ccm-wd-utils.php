<?php

defined( 'ABSPATH' ) || exit;

class CCM_WD_Utils {
    public static function now(): int {
        return time();
    }

    public static function normalize_text( string $value ): string {
        $value = wp_strip_all_tags( $value );
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/\s+/', ' ', $value ) ?? '';
        return $value;
    }

    public static function normalize_alnum( string $value ): string {
        $value = self::normalize_text( $value );
        $value = preg_replace( '/[^a-z0-9]/', '', $value ) ?? '';
        return $value;
    }

    public static function hash_token( string $value ): string {
        if ( '' === $value ) {
            return '';
        }

        return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
    }

    public static function get_client_ip(): string {
        $candidates = array(
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        );

        foreach ( $candidates as $candidate ) {
            if ( '' === $candidate ) {
                continue;
            }

            $parts = array_map( 'trim', explode( ',', (string) $candidate ) );

            foreach ( $parts as $part ) {
                if ( filter_var( $part, FILTER_VALIDATE_IP ) ) {
                    return $part;
                }
            }
        }

        return '';
    }

    public static function get_user_agent(): string {
        return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
    }

    public static function posted( array $data, string $key ): string {
        return isset( $data[ $key ] ) ? sanitize_text_field( (string) $data[ $key ] ) : '';
    }

    public static function contains_fake_address_patterns( string $address1, string $city, string $postcode ): bool {
        $address_check = self::normalize_text( $address1 );
        $city_check    = self::normalize_text( $city );
        $post_check    = self::normalize_alnum( $postcode );

        if ( '' === $address_check || strlen( $address_check ) < 6 ) {
            return true;
        }

        if ( ! preg_match( '/\d/', $address_check ) ) {
            return true;
        }

        if ( preg_match( '/(.)\1{4,}/', $address_check ) ) {
            return true;
        }

        if ( '' === $city_check || strlen( $city_check ) < 2 ) {
            return true;
        }

        if ( '' === $post_check || strlen( $post_check ) < 3 ) {
            return true;
        }

        return false;
    }
}
