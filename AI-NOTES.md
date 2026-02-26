# AI Notes

## 1.00.015 - 2026-02-27

### Objective

Add AJAX-powered Advanced Mode toggle so the Advanced Detection Controls card appears and disappears instantly without a full page reload.

### Implemented

- Created `js/ccm-wd-admin.js` with a vanilla JS listener on the Advanced Mode checkbox that:
  - Animates the Advanced Detection Controls card in/out (opacity + translateY transition).
  - Sends an AJAX POST (`ccm_wd_toggle_advanced`) to persist the setting immediately.
- Added `ajax_toggle_advanced()` handler in `CCM_WD_Admin` — verifies nonce + capability, merges the toggled value into the current settings array, and saves.
- Registered `wp_ajax_ccm_wd_toggle_advanced` action in `register_hooks()`.
- Updated `enqueue_admin_assets()` to enqueue the JS file (footer, versioned) and pass `ajaxUrl` + `nonce` via `wp_localize_script()`.
- Advanced Detection Controls card is now always rendered in the DOM with `id="ccm-wd-advanced-card"` and hidden via inline `display:none` when advanced mode is off.
- Advanced Mode checkbox gained `id="ccm-wd-advanced-mode"` for JS targeting.
- Removed the PHP `<?php if ( ! empty( $settings['advanced_mode'] ) ) : ?>` conditional so the card is present in the HTML regardless of the saved state.

### Why this approach

- Persisting the toggle via AJAX means the saved state is always consistent with the UI — if the user refreshes the page the card stays in the correct visibility state.
- Rendering the card in the DOM (hidden) rather than lazy-loading HTML ensures all form fields are always submitted on Save, preventing data loss if the user toggles advanced on, edits values, then saves.
- Vanilla JS with no jQuery dependency keeps the script small and fast.

## 1.00.014 - 2026-02-27

### Objective

Redesign the admin UI to match the CCM-Tools design system for consistent branding across Click Click Media plugins.

### Implemented

- Created `css/ccm-wd-admin.css` with a complete design system adapted from CCM-Tools:
  - CSS custom properties (design tokens) for brand colours, status colours, neutrals, spacing, radii, shadows, transitions, and typography.
  - Dark gradient header bar with brand title, version badge, and pill-style tab navigation.
  - Card-based content layout with blue accent borders on headings.
  - Stats grid with large metric values for the Overview dashboard.
  - Styled tables with hover effects replacing WordPress `widefat striped`.
  - Badge system (success/error/warning/info/neutral) for status indicators.
  - Toggle switch controls replacing plain checkboxes for enable/advanced settings.
  - Alert boxes with left-border accents for info/success/warning/error messages.
  - Styled form controls (select, number input, textarea) with focus rings.
  - Form table layout for advanced settings grid.
  - IP list display as inline pill badges.
  - Responsive breakpoints for tablet and mobile.
  - WordPress admin overrides to harmonise submit buttons and hide default notices.
- Added `enqueue_admin_assets()` method to load the CSS only on the plugin's admin page (`woocommerce_page_ccm-woo-defender`).
- Rewrote `render_page()`: dark header bar with version badge, pill-style tab navigation, styled alert messages for save/reset/clear confirmations.
- Rewrote `render_overview()`: stats grid at top (Checkout Attempts, Blocked, Active Blocks, Force Block status), Status Details card with badge indicators, Last Checkout Request diagnostics card, How Defender Works info card, Data Management card with confirm-dialog danger button.
- Rewrote `render_settings()`: Easy Setup card with toggle switch rows, styled select dropdown, number inputs, textarea for IP list; Advanced Detection Controls card (conditional) with form table layout for signal weights and trigger thresholds; Save and Reset buttons using the new design system.
- All CSS classes use `ccm-wd-` prefix to avoid conflicts if CCM-Tools is active on the same site.

### Why this approach

- Consistent branding across plugins builds trust and reduces cognitive load for store administrators.
- CSS custom properties enable easy theme adjustments without touching component styles.
- Toggle switches and badge indicators provide clearer visual feedback than plain checkboxes and text labels.
- Card-based layout with stats grid follows the same information hierarchy pattern proven in CCM-Tools.
- The dark sticky header and pill navigation provide persistent context when scrolling long settings pages.

## 1.00.013 - 2026-02-27

### Objective

Fix the critical issue where checkout blocking never fires on WooCommerce block-based (Store API) checkout pages, plus fix a false-positive scoring bug.

### Bugs fixed

1. **Block-based checkout completely unsupported (critical)** — The plugin only hooked into `woocommerce_checkout_process` and `woocommerce_after_checkout_validation`, which are classic (shortcode) checkout hooks. Modern WooCommerce (8.3+) defaults to block-based checkout routed through the Store API (`/wc/store/v1/checkout`). These hooks never fire for block checkout, so all blocking — manual IP, force-block, and risk-score — was silently bypassed.

2. **`repeat_after_blocks` false positives (major)** — The `blocked_attempts_recent` metric in the analyzer counted ALL blocked events in the lookback window regardless of who was blocked. After a few legitimate fraud blocks, every subsequent checkout by any customer received +30 to their risk score from `repeat_after_blocks`, potentially causing false positives for legitimate buyers.

3. **`prune_blocks()` wrote to DB during reads (minor)** — `get_blocks()` called `prune_blocks()` which always ran `update_option()` as a side-effect, even on read-only paths like `is_blocked_token()`.

4. **No cleanup on plugin deactivation (minor)** — Ephemeral data (force-block, diagnostics, updater transients) was never cleaned up.

### Implemented

- Added `woocommerce_store_api_checkout_update_order_from_request` hook handler in checkout guard for block-based checkout.
- New `store_api_checkout_guard()` method reads billing data from the `WC_Order` object (already populated by Store API) and performs manual IP check → force-block check → risk-score evaluation.
- Blocks are thrown as `RouteException` (WC Store API native) so the block-checkout UI shows the error correctly.
- New `build_context_from_order()` helper extracts scoring context from a `WC_Order` (vs `build_context()` which reads POST data for classic checkout).
- Fixed `blocked_attempts_recent` to only count events matching the current visitor's IP, email, or UA hash.
- `prune_blocks()` now accepts a `$persist` parameter; read paths pass `false` to avoid DB writes.
- Added `register_deactivation_hook` to clean up force-block, last-request, and updater transients.

### Why this approach

- The Store API hook `woocommerce_store_api_checkout_update_order_from_request` fires after the order object is populated but before payment processing — the ideal point to validate and reject.
- Using `RouteException` is the WooCommerce-documented way to surface errors in block checkout.
- Per-visitor filtering of `blocked_attempts_recent` eliminates score inflation that could block innocent customers.

## 1.00.012 - 2026-02-27

### Objective

Resolve cases where checkout still completes despite manual block expectations.

### Implemented

- Added early enforcement on `woocommerce_checkout_process` for manual IP and force-block checks.
- Kept validation enforcement on `woocommerce_after_checkout_validation` for compatibility.
- Added request-level diagnostics capture to store:
	- resolved client IP,
	- `REMOTE_ADDR`, `HTTP_X_FORWARDED_FOR`, `HTTP_CF_CONNECTING_IP`,
	- hook name,
	- blocked flag and reason.
- Added “Last observed checkout request” panel in admin Overview.

### Why this approach

- Some checkout flows/themes can behave differently around validation timing.
- Dual-hook enforcement + captured request diagnostics gives both stronger blocking reliability and fast root-cause visibility.

## 1.00.011 - 2026-02-27

### Objective

Provide visible, easy-to-manage IP blocking in admin and ensure those manual blocks are enforced deterministically at checkout.

### Implemented

- Added `Manual blocked IP list` setting (one IP per line) with validation/sanitization.
- Added hard enforcement path in checkout guard (`manual_ip_block`) before risk scoring.
- Added Overview visibility:
	- manual blocked IP count,
	- full configured manual IP list.
- Added WP-CLI diagnostics command: `wp ccm-wd runtime-ip` to inspect runtime IP/header resolution.

### Why this approach

- Gives admins a straightforward “list of blocked IPs” in UI.
- Eliminates uncertainty when testing IP blocks behind proxies/CDNs.

## 1.00.010 - 2026-02-27

### Objective

Provide a reliable way to test frontend blocked UX even when real client IP visibility is affected by proxy/CDN/network layers.

### Implemented

- Added global force-block mode in store with expiry support.
- Checkout guard now enforces force-block before analyzer evaluation.
- Added WP-CLI commands:
	- `wp ccm-wd force-block --minutes=<n>`
	- `wp ccm-wd force-block-status`
	- `wp ccm-wd clear-force-block`
- Added admin overview visibility for force-block status.
- Documented deterministic frontend test flow in README.

### Why this approach

- Removes uncertainty from IP/header forwarding during UX validation.
- Lets testers confirm the real customer-facing blocked behavior immediately.

## 1.00.009 - 2026-02-27

### Objective

Clarify and improve simulation mode coverage so users can test preset strictness levels directly.

### Implemented

- Added `--profile=<lenient|balanced|strict>` to run a specific preset in simulation.
- Added `--all-profiles=1` to run Lenient, Balanced, and Strict in one command.
- Simulation now restores original settings after completion.
- Added README guidance for profile comparison workflow.

### Why this approach

- Makes it easy to answer “which mode is this testing?”
- Enables quick confidence testing and false-positive tuning across all presets.

## 1.00.008 - 2026-02-27

### Objective

Fix remaining deprecation noise shown before simulator output on some WP-CLI environments.

### Implemented

- Moved deprecation suppression earlier in lifecycle (during CLI command registration) for `ccm-wd` commands.
- Kept per-command override support with `--allow-deprecations=1`.
- Updated README guidance to reflect early suppression behavior.

### Why this approach

- Some notices were emitted before command callback execution.
- Early suppression catches that phase while preserving opt-in raw debugging mode.

## 1.00.007 - 2026-02-27

### Objective

Improve simulation usability by reducing noisy deprecation output and clarify test interpretation.

### Implemented

- Updated `wp ccm-wd simulate` to suppress `E_DEPRECATED` and `E_USER_DEPRECATED` notices by default during the run.
- Added opt-in flag `--allow-deprecations=1` for full raw output when debugging runtime environment issues.
- Documented this behavior in README test instructions.

### Why this approach

- The deprecation notices shown were from WP-CLI bundled vendor libraries, not Defender logic.
- Cleaner output makes it easier to verify actual fraud scoring and block transitions.

## 1.00.006 - 2026-02-27

### Objective

Provide an easy automated way to validate fraud scoring and blocking behavior end-to-end.

### Implemented

- Added WP-CLI simulation command: `wp ccm-wd simulate`.
- Command generates fraud-like identity churn attempts against the same gateway/amount signature.
- For each attempt, it evaluates score and block outcome, persists the event, and applies block tokens when triggered.
- Outputs a table of attempts with `score`, `blocked`, and `reasons` so behavior is immediately visible.
- Added README instructions with practical command examples and expected outcomes.

### Why this approach

- Gives repeatable, scriptable validation without relying on manual checkout submissions.
- Lets teams compare profile strictness (`Lenient`, `Balanced`, `Strict`) quickly.

## 1.00.005 - 2026-02-27

### Objective

Add GitHub release-based plugin update detection in WordPress and support forced refresh via `?force-check=1`, following the same updater approach used in `ccm-tools`.

### Implemented

- Added dedicated updater class: `includes/class-ccm-wd-updater.php`.
- Hooked updater into WordPress plugin update lifecycle:
	- `pre_set_site_transient_update_plugins`,
	- `site_transient_update_plugins`,
	- `plugins_api`,
	- `upgrader_post_install`,
	- GitHub HTTP auth/header hooks.
- Added force-check handling for:
	- `plugins.php?force-check=1`,
	- `update-core.php?force-check=1`.
- Added early `admin_init` cache-bust path to clear updater and update transients.
- Bootstrapped updater from main plugin file and documented behavior in README.

### Why this approach

- Matches known-good implementation pattern already validated in your `ccm-tools` workflow.
- Gives reliable update visibility directly in WordPress Plugins screen.
- Keeps manual override (`force-check=1`) for immediate refresh during release verification.

## 1.00.004 - 2026-02-27

### Objective

Provide a comprehensive, easy-to-understand explanation of exactly how Defender works and why it is effective against sporadic WooCommerce fraud attempts.

### Implemented

- Replaced vague admin header copy with clear behavior-focused wording.
- Added an in-product explanation panel on the Overview tab covering:
	- what signals are checked,
	- how scoring decisions are made,
	- how temporary blocking works,
	- how failed/cancelled outcomes improve detection,
	- why this outperforms basic rate limiting.
- Expanded README with a dedicated plain-English section: “How and why it works”.

### Why this approach

- Store owners need confidence in protection decisions without reading source code.
- Clear explanation reduces misconfiguration and support overhead.

### Next ideas

- Add per-signal live counters in Overview for transparency.
- Add a simulation tool to preview score outcomes before changing settings.

## 1.00.003 - 2026-02-27

### Objective

Prioritize ease of use with preset-based setup while still supporting advanced tuning for specific store risk profiles.

### Implemented

- Added `Easy Setup` flow with profile presets:
	- `Lenient` (fewer false positives),
	- `Balanced` (recommended),
	- `Strict` (maximum defense).
- Added `Advanced mode` toggle in settings.
- In Easy mode, fraud detection uses profile defaults.
- In Advanced mode, users can override profile values with manual weights and trigger thresholds.
- Updated analyzer to read effective settings derived from preset + mode.
- Updated Overview to show current mode and selected preset.

### Why this approach

- Store owners can start safely in minutes without understanding every signal.
- Power users still get full control when needed.

### Next ideas

- Add contextual helper tooltips and preset preview summaries.
- Add test-simulation panel to estimate block score with sample inputs.

## 1.00.002 - 2026-02-27

### Objective

Continue with an easy-to-use admin UI and appropriate, configurable protection settings.

### Implemented

- Added a dedicated settings model (`ccm_wd_settings`) with bounded sanitization and defaults.
- Added Settings UI under WooCommerce > CCM Woo Defender:
	- enable/disable protection,
	- risk threshold,
	- block duration,
	- lookback window,
	- per-signal score weights,
	- per-signal trigger minimums.
- Added save and reset-to-default flows with nonce/capability checks.
- Added tabbed admin UX (`Overview`, `Settings`) for better usability.
- Wired analyzer and checkout guard to use persisted settings.

### Why this approach

- Gives store teams direct control over strictness and false-positive balance without code changes.
- Keeps plugin lightweight and local-only while improving operational usability.

### Next ideas

- Add quick presets (`Balanced`, `Strict`, `Lenient`) to simplify first-time setup.
- Add short explanations beside each signal with examples.

## 1.00.001 - 2026-02-27

### Objective

Create the first production-capable baseline of `CCM Woo Defender` to reduce WooCommerce transaction spam/fraud attempts without external services.

### Implemented

- Plugin bootstrap and WooCommerce dependency gate.
- HPOS compatibility declaration.
- Checkout-time fraud analysis and blocking pipeline.
- Persistent local event history and blocklist token store.
- Multi-signal fraud scoring (beyond basic rate limits).
- Order status feedback loop (`failed`/`cancelled`) to improve detection.
- WooCommerce admin screen with quick stats and secure reset action.
- Privacy-safe hashed token approach for IP/email/address/device/payment signatures.

### Why this approach

- Fraud pattern in provided screenshots is not strictly burst-rate; it is identity churn around stable order value/gateway.
- Scoring across correlated signals catches low-frequency, high-consistency abuse that bypasses simple throttles.
- Local-only design stays lightweight and avoids external dependencies.

### Planned next iteration ideas

- Add optional challenge mode for medium-risk requests.
- Add configurable signal weights in admin settings.
- Add audit export and per-gateway sensitivity profiles.
