# AI Notes

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
