/**
 * The trip page's lightbox, the parts that need a script.
 *
 * One lightbox serves both sets of photographs on the trip page: the trip's
 * own (the mosaic) and the boat's (the rail in «Το σκάφος»). Each panel says
 * which set it belongs to in `data-lightbox`, and everything here stays inside
 * that set — the arrows on a boat photograph never step into the trip's.
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
 * ## It adds, it does not replace
 *
 * The lightbox is links and CSS `:target`, and the page under it is held still
 * by CSS too. Opening, closing and stepping all work with this file blocked,
 * missing or never requested — HOS-4's promise is intact. What this adds:
 *
 * - arrow keys and Escape;
 * - a swipe on a touch screen;
 * - focus moved into the open panel, kept there on Tab, and returned to the
 *   photograph that opened it on close — with the page where it was, rather
 *   than scrolled to the anchor the no-script close link names.
 *
 * `location.replace` rather than assignment for stepping and closing, so walking
 * through nine photographs leaves one history entry instead of nine and Back
 * returns to the page rather than to the previous picture.
 */
(function () {
    'use strict';

    var boxes = Array.prototype.slice.call(document.querySelectorAll('.lightbox'));

    if (boxes.length === 0) {
        return;
    }

    // The link that opened the lightbox, and where the page was at the time.
    var opener = null;
    var openerScroll = null;

    // Which control to focus in the panel about to open: `next` after stepping
    // forward, so holding Enter on it walks the whole set.
    var pendingFocus = null;

    function current() {
        return document.querySelector('.lightbox:target');
    }

    function isBox(element) {
        return element !== null && boxes.indexOf(element) !== -1;
    }

    function setOf(box) {
        var name = box.getAttribute('data-lightbox');

        return boxes.filter(function (other) {
            return other.getAttribute('data-lightbox') === name;
        });
    }

    function controls(box) {
        return Array.prototype.slice.call(box.querySelectorAll('a[href]:not([tabindex="-1"])'));
    }

    function focusInside(box) {
        var target = (pendingFocus && box.querySelector('.lightbox-step .' + pendingFocus))
            || box.querySelector('.lightbox-close');

        pendingFocus = null;

        if (target) {
            target.focus({ preventScroll: true });
        }
    }

    function show(box, focus) {
        pendingFocus = focus || null;
        location.replace('#' + box.id);
        // `hashchange` is asynchronous; the panel is already the target.
        focusInside(box);
    }

    function step(by) {
        var open = current();

        if (open === null) {
            return;
        }

        var set = setOf(open);
        var next = set.indexOf(open) + by;

        // Stops at both ends rather than wrapping. A gallery that jumps from the
        // last photograph back to the first reads as a bug the first time it
        // happens.
        if (next >= 0 && next < set.length) {
            show(set[next], by > 0 ? 'next' : 'prev');
        }
    }

    function close() {
        var open = current();

        if (open === null) {
            return;
        }

        var back = open.querySelector('.lightbox-close');
        var y = openerScroll !== null ? openerScroll : window.scrollY;

        location.replace(back ? back.getAttribute('href') : '#');
        window.scrollTo(window.scrollX, y);

        if (opener !== null && document.contains(opener)) {
            opener.focus({ preventScroll: true });
        }

        opener = null;
        openerScroll = null;
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }

        var link = event.target.closest ? event.target.closest('a[href^="#"]') : null;

        if (link === null) {
            return;
        }

        var id = link.getAttribute('href').slice(1);
        var target = id === '' ? null : document.getElementById(id);
        var inside = link.closest('.lightbox');

        // A photograph on the page: let the link open the panel as it would
        // without a script, and remember where to come back to.
        if (inside === null) {
            if (isBox(target)) {
                opener = link;
                openerScroll = window.scrollY;
                pendingFocus = null;
            }

            return;
        }

        // A control inside the open panel: previous, next, close, the scrim.
        event.preventDefault();

        if (isBox(target)) {
            show(target, link.classList.contains('next') ? 'next' : (link.classList.contains('prev') ? 'prev' : null));
        } else {
            close();
        }
    });

    window.addEventListener('hashchange', function () {
        var open = current();

        if (open !== null) {
            // Already done by `show` when the step was ours; this is for a
            // panel opened by a link or by Forward.
            if (!open.contains(document.activeElement)) {
                focusInside(open);
            }
        } else if (opener !== null) {
            // Closed by Back rather than by us.
            opener.focus({ preventScroll: true });
            opener = null;
            openerScroll = null;
        }
    });

    document.addEventListener('keydown', function (event) {
        // Let the browser's own shortcuts through untouched.
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        var open = current();

        // Nothing is open, so these keys mean whatever they usually mean.
        if (open === null) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            close();

            return;
        }

        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            step(event.key === 'ArrowRight' ? 1 : -1);

            return;
        }

        // Tab stays inside the open panel: it is a modal, and the page behind
        // it cannot be seen.
        if (event.key === 'Tab') {
            var list = controls(open);

            if (list.length === 0) {
                return;
            }

            var at = list.indexOf(document.activeElement);
            var to = event.shiftKey
                ? (at <= 0 ? list.length - 1 : at - 1)
                : (at === -1 || at === list.length - 1 ? 0 : at + 1);

            event.preventDefault();
            list[to].focus({ preventScroll: true });
        }
    });

    // A swipe: mostly sideways and long enough not to be a tap.
    var startX = null;
    var startY = null;

    document.addEventListener('touchstart', function (event) {
        if (current() === null || event.touches.length !== 1) {
            startX = null;

            return;
        }

        startX = event.touches[0].clientX;
        startY = event.touches[0].clientY;
    }, { passive: true });

    document.addEventListener('touchend', function (event) {
        if (startX === null || current() === null) {
            return;
        }

        var dx = event.changedTouches[0].clientX - startX;
        var dy = event.changedTouches[0].clientY - startY;

        startX = null;

        if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.5) {
            step(dx < 0 ? 1 : -1);
        }
    }, { passive: true });

    // Opened straight from a link somebody shared, or on reload.
    if (current() !== null) {
        focusInside(current());
    }
})();
