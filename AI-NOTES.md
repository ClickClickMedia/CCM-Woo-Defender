# AI Notes

---

## Versioning Policy

**Format:** `Major.Minor.Patch` (e.g. `1.2.0`). Each segment is a plain integer — no leading zeros. Minor and Patch may range from 0 to 99.

### When to increment

| Segment | When | Examples |
|---------|------|----------|
| **Major** (x.0.0) | Breaking changes, major architecture rewrites, removal of existing features, or changes that require user action on upgrade. | Complete rewrite of scoring engine; dropping PHP 8.1 support; changing stored data format in a non-backwards-compatible way. |
| **Minor** (1.x.0) | New user-facing features or capabilities. Resets Patch to 0. | Adding GeoIP country blocking (1.1.0); adding History tab (1.2.0); adding email notification system; adding a new admin tab or WP-CLI command group. |
| **Patch** (1.2.x) | Bug fixes, performance improvements, UI polish, copy changes, code refactors that don't add or remove capabilities. | Fixing a false-positive scoring bug; CSS tweaks; improving error messages; optimising a database query. |

### Rules

1. **One bump per release.** If a release contains both a new feature and bug fixes, bump Minor (the higher-impact change wins).
2. **Assess before committing.** Before finalising a release, determine the increment type and set the version in both the plugin header (`Version:`) and the `CCM_WD_VERSION` constant.
3. **Tag format.** GitHub release tags use `v` prefix: `v1.2.0`.
4. **AI-NOTES entries.** New changelog entries in this file use the bare version without `v`: `## 1.2.0 - YYYY-MM-DD`.
5. **No retroactive renumbering.** Historical entries (1.00.01 – 1.00.19) keep their original numbers.
6. **README.md** version badge should match the current release.

---

## 1.2.5 - 2026-02-27

### Changed
- Settings page layout: Easy Setup, Advanced Settings, GeoIP, and Blocked IPs are now four separate cards.
- Blocked IPs moved from inside Easy Setup to its own dedicated card.
- Advanced Detection Controls appear as a separate card directly below Easy Setup (toggled by Advanced Mode).

## 1.2.4 - 2026-02-27

### Objective

Prevent IP addresses from wrapping in the History table.

### Fixed

- Changed `.ccm-wd-ip-code` from `word-break: break-all` to `white-space: nowrap` so IP addresses always display on one line.
- Increased history table `min-width` from 820px to 960px to accommodate the wider IP column.

---

## 1.2.3 - 2026-02-27

### Objective

Fix History tab timestamps to use the WordPress site timezone instead of UTC.

### Fixed

- Replaced `gmdate()` with `wp_date()` in History tab date/time rendering so timestamps display in the site's configured timezone (e.g. Australia/Sydney) instead of UTC. Removed the hardcoded "UTC" suffix.

### Note on IP addresses

- Events stored before the `client_ip` field was added (pre-1.2.0) will continue to show "N/A" for IP Address. This is expected — new events record IPs correctly.

---

## 1.2.2 - 2026-02-27

### Objective

Fix History tab Country column showing billing country instead of GeoIP country for legacy events.

### Fixed

- Country column now parses the GeoIP country code from the reasons string (`geoip_country_block:XX` / `geoip_country_score:XX`) as a fallback when the `geoip_country` field is empty. This correctly shows `CN`, `US`, etc. for events stored before the `geoip_country` field was added.

### Why this approach

- Events stored between the GeoIP feature (1.1.0) and the History tab (1.2.0) have the country only in the reasons string, not as a dedicated field. Parsing it avoids requiring a data migration.

---

## 1.2.1 - 2026-02-27

### Objective

Remove all test/debug code and diagnostic overhead to make the plugin lightweight for production.

### Removed

- Deleted `tests/test-checkout-spam.php` (standalone CLI spam test script).
- Deleted `includes/class-ccm-wd-cli-test.php` (WP-CLI commands: `simulate`, `force-block`, `clear-force-block`, `force-block-status`, `runtime-ip`).
- Removed force-block feature entirely (Store methods, checkout guard checks, admin stat box, deactivation cleanup). This was a test-only facility with no production UI to activate it.
- Removed `set_last_request_context()` / `get_last_request_context()` diagnostic tracking — eliminated an `update_option()` call on every checkout attempt. The History tab now provides superior visibility into checkout activity.
- Removed "Last Checkout Request" card from admin Overview (superseded by History tab).
- Removed "Force Block" stat box from admin Overview.
- Cleaned up README: removed WP-CLI test sections, force-block documentation, runtime-ip section.

### Why this approach

- Test/simulation code has no place in a production release — it adds attack surface and code weight.
- The `set_last_request_context()` call wrote to `wp_options` on every single checkout attempt purely for a diagnostic panel that is now redundant with the History tab.
- Force-block was only triggerable via the now-removed CLI commands and had no admin UI, making it dead code.

---

## 1.2.0 - 2026-02-27

### Objective

Add a History admin tab showing a paginated log of checkout events with IP address and country information. Fix versioning to standard semantic format.

### Implemented

- Added **History tab** to the admin UI (between Overview and Settings).
  - Paginated table (30 per page) with columns: Date/Time, IP Address, Country, Gateway, Total, Score, Status, Reasons.
  - Country column shows GeoIP-resolved country (with fallback to billing country); warns when GeoIP and billing country differ.
  - Score is colour-coded: green (<40), amber (40–69), red (≥70).
  - Blocked rows have a subtle red background tint.
  - Responsive horizontal scroll on smaller screens.
- Added `client_ip` (plaintext) to event context in both `build_context()` and `build_context_from_order()`, and to `track_order_outcome()`.
- Added `geoip_country` field to all 9 `add_event()` call sites in the checkout guard.
- Added `get_history_events()` and `get_events_count()` methods to `CCM_WD_Store`.
- Added `render_history()` and `render_pagination()` methods to `CCM_WD_Admin`.
- Added History-specific CSS: table with dark header, IP code badges, country badges with tooltips, colour-coded score pills, reason tags, pagination controls, responsive breakpoints.
- **Versioning overhaul:** switched from zero-padded `1.00.xx` to standard `Major.Minor.Patch` format. Normalised `get_github_version()` in the updater to strip leading zeros from GitHub tags so `version_compare()` works reliably across old and new tag formats.
- Added versioning policy to AI-NOTES.md.

### Why this approach

- A dedicated History tab gives store admins immediate visibility into checkout activity patterns without WP-CLI.
- Storing `client_ip` alongside hashed tokens enables actionable diagnostics while privacy-safe hashes remain the basis for scoring.
- GeoIP country on each event lets admins spot geographic attack patterns at a glance.
- Standard semver prevents `version_compare()` edge cases and communicates change magnitude to users.

---

## 1.1.0 - 2026-02-27

### Objective

Add GeoIP-based country blocking/scoring using the MaxMind GeoLite2 web service.

### Note

This release was originally shipped across versions 1.00.17 – 1.00.19 under the old numbering scheme. Retroactively classified as a Minor release (new feature).

---

## 1.00.16 - 2026-02-27

### Objective

Clean up the Advanced Detection Controls layout for consistent alignment, and add info-icon modals explaining each option.

### Implemented

- Replaced `<table class="ccm-wd-form-table">` markup for Signal Weights and Trigger Thresholds with `ccm-wd-setting-row` flex rows (same layout as Risk Threshold / Easy Setup). All value inputs are now right-aligned in a consistent column.
- Added a Dashicons `info-outline` button next to every advanced setting label (`data-modal="key"`).
- Added a shared modal overlay (`#ccm-wd-modal-overlay`) rendered once at the end of the settings form; populated dynamically by JS.
- New CSS: `.ccm-wd-info-btn` (inline icon button), `.ccm-wd-modal-overlay` (fixed backdrop with fade-in animation), `.ccm-wd-modal` (centered card with slide-up animation), `.ccm-wd-modal-header`, `.ccm-wd-modal-close`, `.ccm-wd-modal-body`.
- Expanded `js/ccm-wd-admin.js` with an `infoContent` object containing plain-English descriptions for all 14 advanced settings, plus `openModal()` / `closeModal()` helpers, delegated click on `.ccm-wd-info-btn`, overlay click-to-dismiss, and Escape key support.
- Enqueued `dashicons` as a CSS dependency so the info icon renders on the plugin page.

### Why this approach

- Flex rows give pixel-perfect right-alignment of all number inputs without relying on table column widths.
- A single shared modal element avoids 14 separate hidden panels in the DOM.
- Delegated click handling means info buttons work even if the advanced card is toggled in/out dynamically.
- Dashicons is already shipped with WordPress admin — zero extra weight.

## 1.00.15 - 2026-02-27

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

## 1.00.14 - 2026-02-27

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

## 1.00.13 - 2026-02-27

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

## 1.00.12 - 2026-02-27

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

## 1.00.11 - 2026-02-27

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

## 1.00.10 - 2026-02-27

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

## 1.00.09 - 2026-02-27

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

## 1.00.08 - 2026-02-27

### Objective

Fix remaining deprecation noise shown before simulator output on some WP-CLI environments.

### Implemented

- Moved deprecation suppression earlier in lifecycle (during CLI command registration) for `ccm-wd` commands.
- Kept per-command override support with `--allow-deprecations=1`.
- Updated README guidance to reflect early suppression behavior.

### Why this approach

- Some notices were emitted before command callback execution.
- Early suppression catches that phase while preserving opt-in raw debugging mode.

## 1.00.07 - 2026-02-27

### Objective

Improve simulation usability by reducing noisy deprecation output and clarify test interpretation.

### Implemented

- Updated `wp ccm-wd simulate` to suppress `E_DEPRECATED` and `E_USER_DEPRECATED` notices by default during the run.
- Added opt-in flag `--allow-deprecations=1` for full raw output when debugging runtime environment issues.
- Documented this behavior in README test instructions.

### Why this approach

- The deprecation notices shown were from WP-CLI bundled vendor libraries, not Defender logic.
- Cleaner output makes it easier to verify actual fraud scoring and block transitions.

## 1.00.06 - 2026-02-27

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

## 1.00.05 - 2026-02-27

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

## 1.00.04 - 2026-02-27

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

## 1.00.03 - 2026-02-27

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

## 1.00.02 - 2026-02-27

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

## 1.00.01 - 2026-02-27

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
