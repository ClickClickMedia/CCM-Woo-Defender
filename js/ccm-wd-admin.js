/**
 * CCM Woo Defender – Admin JS
 *
 * Handles the Advanced Mode toggle via AJAX so the advanced
 * detection controls card appears/disappears without a page reload.
 */
( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        var toggle   = document.getElementById( 'ccm-wd-advanced-mode' );
        var card     = document.getElementById( 'ccm-wd-advanced-card' );

        if ( ! toggle || ! card ) {
            return;
        }

        toggle.addEventListener( 'change', function () {
            var enabled = toggle.checked;

            // Immediately show/hide for snappy UX.
            if ( enabled ) {
                card.style.display = '';
                card.style.opacity = '0';
                card.style.transform = 'translateY(-10px)';
                // Trigger reflow so the transition runs.
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

            // Persist via AJAX.
            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', ccmWdAdmin.ajaxUrl, true );
            xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
            xhr.send(
                'action=ccm_wd_toggle_advanced' +
                '&_ajax_nonce=' + encodeURIComponent( ccmWdAdmin.nonce ) +
                '&advanced_mode=' + ( enabled ? '1' : '0' )
            );
        } );
    } );
} )();
