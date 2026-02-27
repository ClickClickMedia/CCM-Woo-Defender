# CCM Woo Defender

Lightweight fraud defense plugin for WooCommerce checkout abuse patterns (card/paypal/gateway spamming), with no external APIs.

## Version

`1.2.4`

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
  - secure reset of Woo Defender data.
- Settings are stored locally (no external APIs/libraries).

## How and why it works (plain English)

Most checkout attacks are no longer simple high-speed bursts. Attackers often place attempts slowly, changing names and addresses, to avoid normal rate limits.

Woo Defender handles this by combining multiple signals at the same time:

- transaction pattern signals (same gateway + same amount + same country),
- identity churn signals (many different emails/names/addresses around one stable payment pattern),
- origin consistency signals (same IP or same device fingerprint rotating identities),
- quality signals (fake/low-quality address patterns),
- history signals (whether similar attempts were previously blocked).

Each signal contributes to a risk score. If the score crosses the threshold, checkout is blocked before payment processing continues.

After that, Woo Defender temporarily blocks linked fingerprints (hashed tokens) so the same abuse pattern cannot keep retrying under slightly changed details.

Woo Defender also records failed/cancelled outcomes to improve future scoring against your real fraud behavior.

Why this is effective: even when attempts are spread out over hours, fraud campaigns still reuse stable patterns (gateway, amount, device, network behavior). Woo Defender targets those stable correlations rather than relying on speed alone.

Privacy model: sensitive values are stored as HMAC hashes, not raw PII.

## Storage model

Stored in `wp_options` with `autoload=false`:

- `ccm_wd_events` (rolling history, capped, 30-day retention)
- `ccm_wd_blocks` (token => expiry)

All sensitive fields are hashed with HMAC + WordPress salt before storage.

## Hooks / customization

- `ccm_wd_block_threshold` (default from settings, initially `70`)
- `ccm_wd_block_duration` (default from settings, initially `168 hours`)
- `ccm_wd_block_message` (checkout error text)

## GitHub release updates (WordPress auto-update integration)

This plugin includes a GitHub updater (same proven method used in `ccm-tools`) so WordPress can detect new GitHub releases and show update notices in Plugins.

How it works:

- On update checks, it queries `https://api.github.com/repos/ClickClickMedia/CCM-Woo-Defender/releases/latest`.
- It compares the latest release tag (e.g. `v1.2.0`) with the installed plugin version.
- If newer, it injects update data into WordPress plugin update transients.
- The plugin details popup (`View details`) is also populated from the release metadata/changelog.

Force check manually:

- Open `wp-admin/plugins.php?force-check=1`
- or `wp-admin/update-core.php?force-check=1`

This clears updater/transient caches and forces WordPress to fetch fresh release data immediately.

Optional GitHub token:

- For higher API limits (or private repo scenarios), define in `wp-config.php`:
- `define('CCM_WD_GITHUB_TOKEN', 'your_token_here');`

## Manual IP blocklist (admin UI)

You can now manage a visible IP list in WooCommerce > CCM Woo Defender > Settings:

- Field: `Manual blocked IP list`
- Format: one IP per line
- Behavior: these IPs are hard-blocked before scoring logic runs

Enforcement detail:

- Manual IP blocks and risk-score blocking are enforced in **three** checkout hooks:
  - `woocommerce_checkout_process` (classic, early)
  - `woocommerce_after_checkout_validation` (classic, validation)
  - `woocommerce_store_api_checkout_update_order_from_request` (block/Store API checkout)

This ensures blocking works for both the classic (shortcode) checkout **and** the WooCommerce block-based checkout (default since WC 8.3).

The Overview tab also shows:

- Manual blocked IP count
- The current configured IP list

## Notes

- This iteration adds a guided Easy Setup plus optional Advanced Mode for power users.
- It supports both HPOS and legacy posts-based order storage through WooCommerce APIs.
- It supports both classic (shortcode) and block-based (Store API) WooCommerce checkout pages.
- Updater transients are cleaned up on plugin deactivation.
