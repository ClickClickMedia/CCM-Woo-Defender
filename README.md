# CCM Woo Defender

Lightweight fraud defense plugin for WooCommerce checkout abuse patterns (card/paypal/gateway spamming), with no external APIs.

## Version

`1.00.003`

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
- Adds a WooCommerce admin page (`WooCommerce > CCM Woo Defender`) with easy and advanced workflows:
  - Overview tab for live protection metrics,
  - Easy Setup with preset profiles (`Lenient`, `Balanced`, `Strict`),
  - Advanced Mode toggle for expert controls,
  - Settings tab for enable/disable, block duration, lookback window,
  - Advanced controls for editable signal weights and trigger thresholds,
  - one-click reset to defaults,
  - secure reset of Defender data.
- Settings are stored locally (no external APIs/libraries).

## Storage model

Stored in `wp_options` with `autoload=false`:

- `ccm_wd_events` (rolling history, capped, 30-day retention)
- `ccm_wd_blocks` (token => expiry)

All sensitive fields are hashed with HMAC + WordPress salt before storage.

## Hooks / customization

- `ccm_wd_block_threshold` (default from settings, initially `70`)
- `ccm_wd_block_duration` (default from settings, initially `168 hours`)
- `ccm_wd_block_message` (checkout error text)

## Notes

- This iteration adds a guided Easy Setup plus optional Advanced Mode for power users.
- It supports both HPOS and legacy posts-based order storage through WooCommerce APIs.
