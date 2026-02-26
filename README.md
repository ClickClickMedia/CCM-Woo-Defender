# CCM Woo Defender

Lightweight fraud defense plugin for WooCommerce checkout abuse patterns (card/paypal/gateway spamming), with no external APIs.

## Version

`1.00.001`

## What it does

- Verifies WooCommerce is active before enabling protection.
- Declares compatibility with WooCommerce HPOS (`custom_order_tables`).
- Builds a privacy-safe fingerprint (HMAC hashes only) using checkout context:
  - IP, email, billing name, billing address, user agent,
  - payment method + order total + country signature.
- Scores each checkout attempt using layered heuristics:
  - prior block token match,
  - repeated same gateway + same amount + identity churn,
  - same IP with many identities/addresses,
  - same device fingerprint with identity churn,
  - suspicious/fake address patterns,
  - repeated attempts after prior blocks.
- Blocks high-risk attempts before order processing and stores block tokens for future attempts.
- Learns from failed/cancelled WooCommerce orders by feeding them back into the local model.
- Adds a WooCommerce admin page (`WooCommerce > CCM Woo Defender`) for:
  - tracked attempts,
  - blocked attempts,
  - active block token count,
  - secure reset of Defender data.

## Storage model

Stored in `wp_options` with `autoload=false`:

- `ccm_wd_events` (rolling history, capped, 30-day retention)
- `ccm_wd_blocks` (token => expiry)

All sensitive fields are hashed with HMAC + WordPress salt before storage.

## Hooks / customization

- `ccm_wd_block_threshold` (default `70`)
- `ccm_wd_block_duration` (default `7 days`)
- `ccm_wd_block_message` (checkout error text)

## Notes

- This is a first iteration focused on pre-checkout abuse prevention with minimal overhead.
- It supports both HPOS and legacy posts-based order storage through WooCommerce APIs.
