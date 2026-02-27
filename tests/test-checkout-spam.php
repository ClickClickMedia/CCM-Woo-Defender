#!/usr/bin/env php
<?php
/**
 * CCM Woo Defender – Front-end Checkout Spam Tester
 *
 * Simulates fraud-like checkout attempts via the WooCommerce Store API
 * to verify that Woo Defender detects and blocks the pattern.
 *
 * This sends REAL HTTP requests to your site's /wp-json/wc/store/v1/checkout
 * endpoint, just like a browser would. Orders that get through will be created
 * as "pending" (BACS) – delete them afterwards.
 *
 * Requirements:
 *   - PHP 8.1+ with cURL extension
 *   - WooCommerce with block-based checkout enabled
 *   - At least one product published and in stock
 *   - BACS (Bank Transfer) payment gateway enabled (WooCommerce > Settings > Payments)
 *   - Guest checkout allowed (WooCommerce > Settings > Accounts & Privacy)
 *   - CCM Woo Defender installed and protection enabled
 *
 * Usage:
 *   php tests/test-checkout-spam.php --url=https://your-site.com --product=123
 *   php tests/test-checkout-spam.php --url=https://your-site.com --product=123 --attempts=10
 *   php tests/test-checkout-spam.php --url=https://your-site.com --product=123 --gateway=cod --delay=2
 *
 * Options:
 *   --url        Site URL (required)
 *   --product    WooCommerce product ID to add to cart (required)
 *   --attempts   Number of checkout attempts (default: 8)
 *   --gateway    Payment method slug (default: bacs)
 *   --country    Billing country code (default: AU)
 *   --delay      Seconds between attempts (default: 1)
 *   --verbose    Show full response details (default: 0)
 */

// ─── CLI colours ───────────────────────────────────────────────────────────────

function c_red( string $s ): string { return "\033[31m{$s}\033[0m"; }
function c_green( string $s ): string { return "\033[32m{$s}\033[0m"; }
function c_yellow( string $s ): string { return "\033[33m{$s}\033[0m"; }
function c_cyan( string $s ): string { return "\033[36m{$s}\033[0m"; }
function c_bold( string $s ): string { return "\033[1m{$s}\033[0m"; }
function c_dim( string $s ): string { return "\033[2m{$s}\033[0m"; }

// ─── Parse CLI args ────────────────────────────────────────────────────────────

function parse_args(): array {
    global $argv;

    $args = array(
        'url'      => '',
        'product'  => 0,
        'attempts' => 8,
        'gateway'  => 'bacs',
        'country'  => 'AU',
        'delay'    => 1,
        'verbose'  => false,
    );

    foreach ( array_slice( $argv, 1 ) as $arg ) {
        if ( preg_match( '/^--(\w[\w-]*)=(.+)$/', $arg, $m ) ) {
            $key = str_replace( '-', '_', $m[1] );
            $val = $m[2];

            if ( array_key_exists( $key, $args ) ) {
                $args[ $key ] = is_int( $args[ $key ] ) ? (int) $val
                    : ( is_bool( $args[ $key ] ) ? ( '1' === $val || 'true' === strtolower( $val ) ) : $val );
            }
        } elseif ( preg_match( '/^--(\w[\w-]*)$/', $arg, $m ) ) {
            $key = str_replace( '-', '_', $m[1] );
            if ( array_key_exists( $key, $args ) && is_bool( $args[ $key ] ) ) {
                $args[ $key ] = true;
            }
        }
    }

    return $args;
}

// ─── cURL helper ───────────────────────────────────────────────────────────────

function api_request( string $method, string $url, string $cookie_file, string $nonce = '', ?array $body = null ): array {
    $ch = curl_init( $url );

    $headers = array(
        'Content-Type: application/json',
        'Accept: application/json',
    );

    if ( $nonce ) {
        $headers[] = "X-WC-Store-API-Nonce: {$nonce}";
    }

    curl_setopt_array( $ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEFILE     => $cookie_file,
        CURLOPT_COOKIEJAR      => $cookie_file,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'CCM-WD-SpamTester/1.0',
    ) );

    if ( 'POST' === $method ) {
        curl_setopt( $ch, CURLOPT_POST, true );
        if ( null !== $body ) {
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
        }
    }

    $response    = curl_exec( $ch );
    $header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
    $http_code   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $curl_error  = curl_error( $ch );
    curl_close( $ch );

    if ( false === $response ) {
        return array(
            'code'  => 0,
            'body'  => null,
            'nonce' => $nonce,
            'error' => $curl_error,
        );
    }

    $resp_headers = substr( $response, 0, $header_size );
    $resp_body    = substr( $response, $header_size );

    // Extract nonce from response headers.
    $new_nonce = '';
    if ( preg_match( '/X-WC-Store-API-Nonce:\s*(.+)/i', $resp_headers, $m ) ) {
        $new_nonce = trim( $m[1] );
    }
    // Also check the Nonce header (some WC versions).
    if ( ! $new_nonce && preg_match( '/^Nonce:\s*(.+)/im', $resp_headers, $m ) ) {
        $new_nonce = trim( $m[1] );
    }

    return array(
        'code'  => $http_code,
        'body'  => json_decode( $resp_body, true ),
        'nonce' => $new_nonce ?: $nonce,
        'raw'   => $resp_body,
        'error' => '',
    );
}

// ─── Test identities (rotating fraud-like data) ───────────────────────────────

function get_test_identity( int $index, string $country ): array {
    // Rotating identities: same payment pattern, different people.
    $identities = array(
        array( 'first' => 'Sarah',   'last' => 'Johnson',  'email' => 'sarah.johnson.test@example.net',   'address' => '42 Oak Street',        'city' => 'Sydney',    'state' => 'NSW', 'postcode' => '2000', 'phone' => '0400000001' ),
        array( 'first' => 'Michael', 'last' => 'Chen',     'email' => 'mchen.buyer99@example.net',        'address' => '15 Elm Avenue',        'city' => 'Melbourne', 'state' => 'VIC', 'postcode' => '3000', 'phone' => '0400000002' ),
        array( 'first' => 'Emma',    'last' => 'Williams', 'email' => 'emma.w.shopper@example.net',       'address' => '88 Pine Road',         'city' => 'Brisbane',  'state' => 'QLD', 'postcode' => '4000', 'phone' => '0400000003' ),
        array( 'first' => 'David',   'last' => 'Brown',    'email' => 'dbrown.purchase@example.net',      'address' => '3 Maple Lane',         'city' => 'Perth',     'state' => 'WA',  'postcode' => '6000', 'phone' => '0400000004' ),
        array( 'first' => 'Jessica', 'last' => 'Taylor',   'email' => 'jess.taylor.buy@example.net',      'address' => '27 Cedar Drive',       'city' => 'Adelaide',  'state' => 'SA',  'postcode' => '5000', 'phone' => '0400000005' ),
        array( 'first' => 'James',   'last' => 'Wilson',   'email' => 'j.wilson.order@example.net',       'address' => '61 Birch Court',       'city' => 'Hobart',    'state' => 'TAS', 'postcode' => '7000', 'phone' => '0400000006' ),
        array( 'first' => 'Olivia',  'last' => 'Martin',   'email' => 'olivia.m.shop@example.net',        'address' => '9 Willow Place',       'city' => 'Darwin',    'state' => 'NT',  'postcode' => '0800', 'phone' => '0400000007' ),
        array( 'first' => 'Daniel',  'last' => 'Anderson', 'email' => 'dan.anderson.pay@example.net',     'address' => '135 Spruce Boulevard', 'city' => 'Canberra',  'state' => 'ACT', 'postcode' => '2600', 'phone' => '0400000008' ),
        array( 'first' => 'Sophia',  'last' => 'Lee',      'email' => 'sophia.lee.cart@example.net',       'address' => '7 Fern Close',         'city' => 'Newcastle', 'state' => 'NSW', 'postcode' => '2300', 'phone' => '0400000009' ),
        array( 'first' => 'Ethan',   'last' => 'Harris',   'email' => 'ethan.harris.test@example.net',    'address' => '52 Ash Avenue',        'city' => 'Gold Coast','state' => 'QLD', 'postcode' => '4217', 'phone' => '0400000010' ),
        array( 'first' => 'Mia',     'last' => 'Clark',    'email' => 'mia.clark.order22@example.net',    'address' => '19 Poplar Street',     'city' => 'Geelong',   'state' => 'VIC', 'postcode' => '3220', 'phone' => '0400000011' ),
        array( 'first' => 'Noah',    'last' => 'Walker',   'email' => 'noah.walker.buy@example.net',      'address' => '73 Hickory Road',      'city' => 'Wollongong','state' => 'NSW', 'postcode' => '2500', 'phone' => '0400000012' ),
        array( 'first' => 'Isabella','last' => 'Hall',     'email' => 'isabella.hall.pay@example.net',     'address' => '41 Magnolia Way',      'city' => 'Cairns',    'state' => 'QLD', 'postcode' => '4870', 'phone' => '0400000013' ),
        array( 'first' => 'Liam',    'last' => 'Young',    'email' => 'liam.young.shop@example.net',      'address' => '8 Sycamore Lane',      'city' => 'Townsville','state' => 'QLD', 'postcode' => '4810', 'phone' => '0400000014' ),
        array( 'first' => 'Ava',     'last' => 'King',     'email' => 'ava.king.checkout@example.net',    'address' => '55 Chestnut Circle',   'city' => 'Bendigo',   'state' => 'VIC', 'postcode' => '3550', 'phone' => '0400000015' ),
    );

    $id = $identities[ $index % count( $identities ) ];
    $id['country'] = $country;

    // For higher indices, add a numeric suffix to make truly unique.
    if ( $index >= count( $identities ) ) {
        $suffix         = (int) floor( $index / count( $identities ) ) + 1;
        $id['email']    = str_replace( '@', "+r{$suffix}@", $id['email'] );
        $id['first']    = $id['first'] . $suffix;
        $id['address']  = ( $index * 7 + 100 ) . ' ' . explode( ' ', $id['address'], 2 )[1];
    }

    return $id;
}

function build_checkout_body( array $identity, string $gateway ): array {
    $address = array(
        'first_name' => $identity['first'],
        'last_name'  => $identity['last'],
        'address_1'  => $identity['address'],
        'address_2'  => '',
        'city'       => $identity['city'],
        'state'      => $identity['state'],
        'postcode'   => $identity['postcode'],
        'country'    => $identity['country'],
    );

    return array(
        'billing_address' => array_merge( $address, array(
            'email' => $identity['email'],
            'phone' => $identity['phone'],
        ) ),
        'shipping_address' => $address,
        'payment_method'   => $gateway,
    );
}

// ─── Result classification ─────────────────────────────────────────────────────

function classify_result( array $result ): array {
    $code = $result['code'];
    $body = $result['body'];

    if ( ! empty( $result['error'] ) ) {
        return array( 'status' => 'error', 'detail' => 'cURL: ' . $result['error'] );
    }

    // Woo Defender block (HTTP 403 with our error codes).
    if ( 403 === $code ) {
        $error_code = $body['code'] ?? '';
        if ( str_starts_with( $error_code, 'ccm_wd_' ) ) {
            return array( 'status' => 'blocked', 'detail' => $error_code );
        }
        return array( 'status' => 'blocked', 'detail' => 'HTTP 403: ' . ( $body['message'] ?? 'forbidden' ) );
    }

    // Successful order.
    if ( 200 === $code && isset( $body['order_id'] ) ) {
        return array( 'status' => 'passed', 'detail' => 'Order #' . $body['order_id'] . ' created' );
    }

    // WooCommerce validation error (not Woo Defender).
    if ( $code >= 400 ) {
        $msg = $body['message'] ?? ( $body['data']['message'] ?? 'unknown error' );
        $error_code = $body['code'] ?? 'http_' . $code;
        return array( 'status' => 'wc_error', 'detail' => $error_code . ': ' . $msg );
    }

    return array( 'status' => 'unknown', 'detail' => 'HTTP ' . $code );
}

// ─── Formatters ────────────────────────────────────────────────────────────────

function format_status( string $status ): string {
    return match ( $status ) {
        'blocked'  => c_red( 'BLOCKED' ),
        'passed'   => c_green( 'PASSED' ),
        'wc_error' => c_yellow( 'WC ERROR' ),
        'error'    => c_red( 'ERROR' ),
        default    => c_dim( strtoupper( $status ) ),
    };
}

function print_table_row( int $attempt, string $email, string $status_label, string $detail ): void {
    $num   = str_pad( (string) $attempt, 3, ' ', STR_PAD_LEFT );
    $email = str_pad( $email, 38 );
    $stat  = str_pad( strip_ansi( $status_label ), 10 );

    // Calculate padding for status based on actual visible length.
    $visible_len = mb_strlen( strip_ansi( $status_label ) );
    $pad         = 10 - $visible_len;
    $padded_stat = $status_label . str_repeat( ' ', max( 0, $pad ) );

    echo "  {$num}   {$email} {$padded_stat} {$detail}\n";
}

function strip_ansi( string $s ): string {
    return preg_replace( '/\033\[[0-9;]*m/', '', $s );
}

// ─── Main ──────────────────────────────────────────────────────────────────────

function main(): int {
    $args = parse_args();

    // Validate required args.
    if ( '' === $args['url'] || 0 === $args['product'] ) {
        echo c_bold( "\nCCM Woo Defender – Checkout Spam Tester\n" );
        echo c_dim( str_repeat( '─', 50 ) ) . "\n\n";
        echo "Simulates fraud-like checkout attempts via the WooCommerce Store API\n";
        echo "to verify that Woo Defender detects and blocks the pattern.\n\n";
        echo c_yellow( "Usage:\n" );
        echo "  php test-checkout-spam.php --url=https://your-site.com --product=123\n\n";
        echo c_yellow( "Required:\n" );
        echo "  --url=<site-url>      Your WordPress site URL\n";
        echo "  --product=<id>        WooCommerce product ID to add to cart\n\n";
        echo c_yellow( "Optional:\n" );
        echo "  --attempts=<n>        Number of attempts (default: 8)\n";
        echo "  --gateway=<slug>      Payment method (default: bacs)\n";
        echo "  --country=<code>      Billing country code (default: AU)\n";
        echo "  --delay=<seconds>     Pause between attempts (default: 1)\n";
        echo "  --verbose             Show full API responses\n\n";
        echo c_yellow( "Prerequisites:\n" );
        echo "  - BACS (Bank Transfer) or COD payment gateway enabled\n";
        echo "  - Guest checkout allowed\n";
        echo "  - At least one product published and in stock\n";
        echo "  - CCM Woo Defender enabled with protection ON\n\n";
        echo c_dim( "Note: Attempts that pass through will create pending orders.\n" );
        echo c_dim( "Delete test orders from WooCommerce > Orders after testing.\n\n" );
        return 1;
    }

    $site_url = rtrim( $args['url'], '/' );
    $product  = $args['product'];
    $attempts = max( 2, min( 30, $args['attempts'] ) );
    $gateway  = $args['gateway'];
    $country  = strtoupper( $args['country'] );
    $delay    = max( 0, $args['delay'] );
    $verbose  = $args['verbose'];

    echo "\n" . c_bold( 'CCM Woo Defender – Checkout Spam Tester' ) . "\n";
    echo c_dim( str_repeat( '─', 50 ) ) . "\n\n";
    echo "  Site:       " . c_cyan( $site_url ) . "\n";
    echo "  Product ID: " . c_cyan( (string) $product ) . "\n";
    echo "  Gateway:    " . c_cyan( $gateway ) . "\n";
    echo "  Country:    " . c_cyan( $country ) . "\n";
    echo "  Attempts:   " . c_cyan( (string) $attempts ) . "\n";
    echo "  Delay:      " . c_cyan( "{$delay}s" ) . "\n";
    echo "\n";

    // API endpoints.
    $api_base     = "{$site_url}/wp-json/wc/store/v1";
    $cart_url     = "{$api_base}/cart";
    $add_item_url = "{$api_base}/cart/add-item";
    $checkout_url = "{$api_base}/checkout";

    // Counters.
    $passed  = 0;
    $blocked = 0;
    $errors  = 0;

    // Table header.
    echo c_bold( "  #     Email                                  Status     Detail\n" );
    echo c_dim( "  " . str_repeat( '─', 90 ) ) . "\n";

    for ( $i = 1; $i <= $attempts; $i++ ) {
        $identity = get_test_identity( $i - 1, $country );

        // Fresh cookie jar for each "attacker".
        $cookie_file = tempnam( sys_get_temp_dir(), 'ccm_wd_test_' );

        // Step 1: Get cart (establishes WC session + nonce).
        $cart_resp = api_request( 'GET', $cart_url, $cookie_file );

        if ( 0 === $cart_resp['code'] ) {
            print_table_row( $i, $identity['email'], format_status( 'error' ), 'Could not reach site: ' . $cart_resp['error'] );
            $errors++;
            @unlink( $cookie_file );
            continue;
        }

        $nonce = $cart_resp['nonce'];

        if ( $verbose ) {
            echo c_dim( "    [cart] HTTP {$cart_resp['code']}, nonce: {$nonce}\n" );
        }

        // Step 2: Add product to cart.
        $add_resp = api_request( 'POST', $add_item_url, $cookie_file, $nonce, array(
            'id'       => $product,
            'quantity' => 1,
        ) );

        $nonce = $add_resp['nonce'];

        if ( $add_resp['code'] >= 400 ) {
            $msg = $add_resp['body']['message'] ?? 'failed to add product';
            print_table_row( $i, $identity['email'], format_status( 'error' ), "Add-to-cart failed: {$msg}" );
            $errors++;
            @unlink( $cookie_file );
            continue;
        }

        if ( $verbose ) {
            echo c_dim( "    [add-item] HTTP {$add_resp['code']}, nonce: {$nonce}\n" );
        }

        // Step 3: Attempt checkout with this identity.
        $checkout_body = build_checkout_body( $identity, $gateway );
        $checkout_resp = api_request( 'POST', $checkout_url, $cookie_file, $nonce, $checkout_body );

        if ( $verbose ) {
            echo c_dim( "    [checkout] HTTP {$checkout_resp['code']}\n" );
            if ( $checkout_resp['body'] ) {
                echo c_dim( '    ' . json_encode( $checkout_resp['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . "\n";
            }
        }

        // Classify result.
        $result = classify_result( $checkout_resp );
        print_table_row( $i, $identity['email'], format_status( $result['status'] ), $result['detail'] );

        if ( 'blocked' === $result['status'] ) {
            $blocked++;
        } elseif ( 'passed' === $result['status'] ) {
            $passed++;
        } else {
            $errors++;
        }

        // Clean up cookie file.
        @unlink( $cookie_file );

        // Delay between attempts.
        if ( $i < $attempts && $delay > 0 ) {
            sleep( $delay );
        }
    }

    // Summary.
    echo c_dim( "\n  " . str_repeat( '─', 90 ) ) . "\n";
    echo "\n" . c_bold( "  Summary\n" );
    echo "  Passed:  " . c_green( (string) $passed ) . ( $passed > 0 ? c_dim( ' (pending orders created – delete from WooCommerce > Orders)' ) : '' ) . "\n";
    echo "  Blocked: " . c_red( (string) $blocked ) . "\n";
    echo "  Errors:  " . c_yellow( (string) $errors ) . "\n\n";

    if ( $blocked > 0 ) {
        echo c_green( "  ✓ Woo Defender is blocking checkout spam!" ) . "\n\n";
    } elseif ( 0 === $errors ) {
        echo c_yellow( "  ⚠ No attempts were blocked. Check that:" ) . "\n";
        echo c_dim( "    - Protection is enabled in WooCommerce > CCM Woo Defender > Settings\n" );
        echo c_dim( "    - Try more attempts (--attempts=12) to exceed the detection threshold\n" );
        echo c_dim( "    - Try a stricter profile or lower the risk threshold in Advanced Mode\n\n" );
    }

    return $blocked > 0 ? 0 : 1;
}

exit( main() );
