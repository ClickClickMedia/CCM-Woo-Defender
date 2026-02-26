# CCM Woo Defender

Lightweight fraud defense plugin for WooCommerce checkout abuse patterns (card/paypal/gateway spamming), with no external APIs.

## Version

`1.00.013`

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

## How and why it works (plain English)

Most checkout attacks are no longer simple high-speed bursts. Attackers often place attempts slowly, changing names and addresses, to avoid normal rate limits.

Defender handles this by combining multiple signals at the same time:

- transaction pattern signals (same gateway + same amount + same country),
- identity churn signals (many different emails/names/addresses around one stable payment pattern),
- origin consistency signals (same IP or same device fingerprint rotating identities),
- quality signals (fake/low-quality address patterns),
- history signals (whether similar attempts were previously blocked).

Each signal contributes to a risk score. If the score crosses the threshold, checkout is blocked before payment processing continues.

After that, Defender temporarily blocks linked fingerprints (hashed tokens) so the same abuse pattern cannot keep retrying under slightly changed details.

Defender also records failed/cancelled outcomes to improve future scoring against your real fraud behavior.

Why this is effective: even when attempts are spread out over hours, fraud campaigns still reuse stable patterns (gateway, amount, device, network behavior). Defender targets those stable correlations rather than relying on speed alone.

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
- It compares the latest release tag (e.g. `v1.00.005`) with the installed plugin version.
- If newer, it injects update data into WordPress plugin update transients.
- The plugin details popup (`View details`) is also populated from the release metadata/changelog.

Force check manually:

- Open `wp-admin/plugins.php?force-check=1`
- or `wp-admin/update-core.php?force-check=1`

This clears updater/transient caches and forces WordPress to fetch fresh release data immediately.

Optional GitHub token:

- For higher API limits (or private repo scenarios), define in `wp-config.php`:
- `define('CCM_WD_GITHUB_TOKEN', 'your_token_here');`

## How to test blocking automatically (WP-CLI)

Defender now includes a simulation command that generates fraud-like attempts and prints scoring/block decisions.

Run from your WordPress root:

- `wp ccm-wd simulate`

Useful variants:

- `wp ccm-wd simulate --attempts=8 --gateway=paypal --total=139.20 --clear-first=1`
- `wp ccm-wd simulate --attempts=10 --gateway=stripe --ip=169.148.67.2 --country=AU`
- `wp ccm-wd simulate --profile=strict --attempts=8`
- `wp ccm-wd simulate --all-profiles=1 --attempts=8 --gateway=paypal --total=139.20`

Profile behavior:

- Default run uses your currently selected preset profile.
- `--profile=<lenient|balanced|strict>` runs one preset temporarily for test purposes.
- `--all-profiles=1` runs Lenient, Balanced, and Strict sequentially for side-by-side comparison.
- During profile-based simulation, Advanced Mode overrides are temporarily bypassed so preset behavior is tested cleanly.

Deprecation notice handling:

- By default, simulation suppresses PHP deprecation notices from external WP-CLI vendor libraries so output stays readable.
- Suppression is now applied early during command registration so pre-command CLI rendering warnings are also reduced.
- If you want full raw deprecation output for debugging, add:
- `--allow-deprecations=1`

What success looks like:

- Output table shows each attempt with `score`, `blocked` (`yes/no`), and `reasons`.
- In a normal run, early attempts are often `blocked=no` and later attempts switch to `blocked=yes` as patterns accumulate.
- Final success line prints counts for tracked attempts, blocked attempts, and active block tokens.

Tip:

- Set profile to `Balanced` first, run simulation, then compare with `Strict` and `Lenient` to verify sensitivity.

## Frontend blocked-flow testing (deterministic)

If proxy/CDN IP forwarding makes IP-based tests inconsistent, use force-block mode to guarantee checkout is blocked.

Enable force-block for 30 minutes:

- `wp ccm-wd force-block --minutes=30`

Check status:

- `wp ccm-wd force-block-status`

Now submit checkout on frontend. It should fail with Defender block message every time while active.

Disable when finished:

- `wp ccm-wd clear-force-block`

This is the recommended way to validate customer-facing blocked UX reliably in staging.

## Manual IP blocklist (admin UI)

You can now manage a visible IP list in WooCommerce > CCM Woo Defender > Settings:

- Field: `Manual blocked IP list`
- Format: one IP per line
- Behavior: these IPs are hard-blocked before scoring logic runs

Enforcement detail:

- Manual/force blocks and risk-score blocking are enforced in **three** checkout hooks:
  - `woocommerce_checkout_process` (classic, early)
  - `woocommerce_after_checkout_validation` (classic, validation)
  - `woocommerce_store_api_checkout_update_order_from_request` (block/Store API checkout)

This ensures blocking works for both the classic (shortcode) checkout **and** the WooCommerce block-based checkout (default since WC 8.3).

The Overview tab also shows:

- Manual blocked IP count
- The current configured IP list
- Last observed checkout request diagnostics (hook, block reason, resolved IP, forwarded headers)

## Runtime IP diagnostics

If an expected IP block does not trigger, check what IP Defender actually sees:

- `wp ccm-wd runtime-ip`

This prints resolved client IP plus forwarding headers (`REMOTE_ADDR`, `HTTP_X_FORWARDED_FOR`, `HTTP_CF_CONNECTING_IP`).

## Notes

- This iteration adds a guided Easy Setup plus optional Advanced Mode for power users.
- It supports both HPOS and legacy posts-based order storage through WooCommerce APIs.
- It supports both classic (shortcode) and block-based (Store API) WooCommerce checkout pages.
- Ephemeral data (force-block, diagnostics, updater transients) is cleaned up on plugin deactivation.
