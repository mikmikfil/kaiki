/**
 * Keyboard control for the trip page's photo gallery.
 *
 * ## Why this is a file and not an inline script
 *
 * The hosted pages send `script-src 'self'` with no nonce and no
 * `'unsafe-inline'`. The `$nonce` those templates already carry belongs to
 * `style-src`, so an inline `<script nonce="...">` is signed with the wrong key
 * and the browser drops it silently — which is exactly what happened to the
 * first version of this. Served from the app's own origin it needs no CSP
 * change at all, which is the right trade: a gallery does not justify widening
 * a policy.
 *
 * ## It adds keys and nothing else
 *
 * The gallery and its lightbox are links and CSS `:target`. Opening, closing and
 * stepping through the photographs all work with this file blocked, missing or
 * never requested — HOS-4's promise is intact. This only adds the keys a person
 * expects once a picture is already full screen.
 *
 * `location.replace` rather than assignment, so walking through nine
 * photographs leaves one history entry instead of nine and Back returns to the
 * page rather than to the previous picture.
 */
(function () {
    'use strict';

    var boxes = Array.prototype.slice.call(document.querySelectorAll('.lightbox'));

    if (boxes.length === 0) {
        return;
    }

    document.addEventListener('keydown', function (event) {
        // Let the browser's own shortcuts through untouched.
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        var open = document.querySelector('.lightbox:target');

        // Nothing is open, so these keys mean whatever they usually mean —
        // arrows still scroll the gallery.
        if (open === null) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            location.replace('#gallery');

            return;
        }

        var step = event.key === 'ArrowRight' ? 1 : (event.key === 'ArrowLeft' ? -1 : 0);

        if (step === 0) {
            return;
        }

        var next = boxes.indexOf(open) + step;

        // Stops at both ends rather than wrapping. A gallery that jumps from the
        // last photograph back to the first reads as a bug the first time it
        // happens, and there is no count on screen to explain it.
        if (next >= 0 && next < boxes.length) {
            event.preventDefault();
            location.replace('#' + boxes[next].id);
        }
    });
})();
