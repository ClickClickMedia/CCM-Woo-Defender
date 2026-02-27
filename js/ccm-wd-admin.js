/**
 * CCM Woo Defender – Admin JS
 *
 * Handles:
 *   1. Advanced Mode toggle via AJAX (show/hide card, persist setting).
 *   2. Info-icon modals explaining each advanced detection option.
 */
( function () {
    'use strict';

    /* -------------------------------------------------------
       Info modal content keyed by data-modal attribute value
       ------------------------------------------------------- */
    var infoContent = {
        threshold: {
            title: 'Risk Threshold',
            body:  '<p>The total risk score a checkout attempt must reach before it is blocked.</p>' +
                   '<p><strong>Lower values = stricter.</strong> A threshold of 60 blocks more aggressively; 90 allows more attempts through.</p>' +
                   '<p>Recommended range: 60 – 90. Start at 70 (the default) and adjust based on your false-positive rate.</p>'
        },
        weight_suspicious_address: {
            title: 'Suspicious Address Weight',
            body:  '<p>Points added when the billing address looks suspicious — for example very short fields, numeric-only names, or placeholder patterns commonly used by bots.</p>' +
                   '<p>Higher values make address quality a stronger factor in the overall score.</p>'
        },
        weight_payment_identity_churn: {
            title: 'Gateway + Amount Identity Churn Weight',
            body:  '<p>Points added when the same payment gateway and order total combination is seen with many different email addresses in the detection window.</p>' +
                   '<p>This catches the classic card-testing pattern: same gateway, same amount, rotating identities.</p>'
        },
        weight_ip_identity_churn: {
            title: 'Same IP Identity Churn Weight',
            body:  '<p>Points added when multiple checkout attempts come from the same IP address but with different billing details (names, emails, addresses).</p>' +
                   '<p>Legitimate buyers rarely submit many different identities from one IP in a short time.</p>'
        },
        weight_device_identity_churn: {
            title: 'Same Device Identity Churn Weight',
            body:  '<p>Points added when the same browser/device fingerprint is used across checkout attempts with different billing identities.</p>' +
                   '<p>Even if the attacker rotates IPs (e.g. via proxy), the device fingerprint often stays stable.</p>'
        },
        weight_repeat_after_blocks: {
            title: 'Repeat-After-Blocks Weight',
            body:  '<p>Points added when a visitor keeps attempting checkout after they have already been blocked at least once.</p>' +
                   '<p>Legitimate customers usually stop after a single block message; persistent retries are a strong fraud signal.</p>'
        },
        payment_identity_min_attempts: {
            title: 'Gateway + Amount Min Attempts',
            body:  '<p>The minimum number of checkout attempts with the same gateway + amount before the identity-churn signal can fire.</p>' +
                   '<p>Lower values trigger earlier but may catch legitimate retries. Higher values wait for a clearer pattern.</p>'
        },
        payment_identity_min_unique_emails: {
            title: 'Gateway + Amount Min Unique Emails',
            body:  '<p>The minimum number of distinct email addresses seen on the same gateway + amount combination before the churn signal fires.</p>' +
                   '<p>At least this many different emails must appear within the detection window.</p>'
        },
        ip_identity_min_attempts: {
            title: 'Same IP Min Attempts',
            body:  '<p>How many checkout attempts from a single IP address must be seen before the IP identity-churn signal can activate.</p>' +
                   '<p>Stores behind shared IPs (e.g. corporate NAT) may want a higher value to avoid false positives.</p>'
        },
        ip_identity_min_unique_addresses: {
            title: 'Same IP Min Unique Addresses',
            body:  '<p>The minimum number of distinct billing addresses from a single IP before the signal fires.</p>' +
                   '<p>One person trying multiple cards often uses the same address; many different addresses from one IP is highly suspicious.</p>'
        },
        device_identity_min_attempts: {
            title: 'Same Device Min Attempts',
            body:  '<p>How many checkout attempts from the same device fingerprint must be seen before the device identity-churn signal can activate.</p>' +
                   '<p>A higher value requires more attempts before flagging, reducing false positives from shared devices.</p>'
        },
        device_identity_min_unique_emails: {
            title: 'Same Device Min Unique Emails',
            body:  '<p>The minimum number of distinct email addresses from a single device fingerprint before the churn signal fires.</p>' +
                   '<p>Multiple emails from the same browser session is a strong indicator of automated abuse.</p>'
        },
        repeat_after_blocks_min_attempts: {
            title: 'Repeat-After-Blocks Min Attempts',
            body:  '<p>How many additional checkout attempts a visitor must make after being blocked before the repeat signal fires.</p>' +
                   '<p>Set to 1 to penalise immediately on the first retry after a block; higher values allow a few retries.</p>'
        }
    };

    document.addEventListener( 'DOMContentLoaded', function () {

        /* -------------------------------------------------------
           Advanced Mode Toggle
           ------------------------------------------------------- */
        var toggle   = document.getElementById( 'ccm-wd-advanced-mode' );
        var card     = document.getElementById( 'ccm-wd-advanced-card' );

        if ( toggle && card ) {
            toggle.addEventListener( 'change', function () {
                var enabled = toggle.checked;

                if ( enabled ) {
                    card.style.display = '';
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(-10px)';
                    void card.offsetHeight;
                    card.style.transition = 'opacity .3s ease, transform .3s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                } else {
                    card.style.transition = 'opacity .2s ease, transform .2s ease';
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(-10px)';
                    setTimeout( function () {
                        card.style.display = 'none';
                        card.style.transition = '';
                    }, 220 );
                }

                var xhr = new XMLHttpRequest();
                xhr.open( 'POST', ccmWdAdmin.ajaxUrl, true );
                xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
                xhr.send(
                    'action=ccm_wd_toggle_advanced' +
                    '&_ajax_nonce=' + encodeURIComponent( ccmWdAdmin.nonce ) +
                    '&advanced_mode=' + ( enabled ? '1' : '0' )
                );
            } );
        }

        /* -------------------------------------------------------
           Info Modals
           ------------------------------------------------------- */
        var overlay  = document.getElementById( 'ccm-wd-modal-overlay' );
        var mTitle   = document.getElementById( 'ccm-wd-modal-title' );
        var mBody    = document.getElementById( 'ccm-wd-modal-body' );
        var mClose   = document.getElementById( 'ccm-wd-modal-close' );

        if ( ! overlay ) {
            return;
        }

        function openModal( key ) {
            var data = infoContent[ key ];
            if ( ! data ) {
                return;
            }
            mTitle.textContent = data.title;
            mBody.innerHTML    = data.body;
            overlay.style.display = 'flex';
        }

        function closeModal() {
            overlay.style.display = 'none';
        }

        // Delegate click on all info buttons.
        document.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '.ccm-wd-info-btn' );
            if ( btn ) {
                e.preventDefault();
                openModal( btn.getAttribute( 'data-modal' ) );
                return;
            }
        } );

        // Close on × button, overlay click, or Escape.
        if ( mClose ) {
            mClose.addEventListener( 'click', closeModal );
        }
        overlay.addEventListener( 'click', function ( e ) {
            if ( e.target === overlay ) {
                closeModal();
            }
        } );
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && overlay.style.display !== 'none' ) {
                closeModal();
            }
        } );
    } );
} )();
