/**
 * «Από την οθόνη σας στο κατάστρωμα»: the route travelled, once, when the
 * section comes into view (Mike, 2026-09-24: four seconds from the first stop
 * to the last).
 *
 * ## A file, not an inline script
 *
 * For the reason `gallery.js` gives: the hosted pages send `script-src 'self'`
 * with no nonce for scripts, so only a file from our own origin runs.
 *
 * ## It only starts the animation
 *
 * The route is complete without this file: dots filled, lines solid. The file
 * marks each route "ready" (lines back to dashed, dots empty) and then "on"
 * when a good part of it is on screen, which is what the stylesheet animates.
 * Nothing is marked for somebody who asked for reduced motion, or for a
 * browser without IntersectionObserver, so they keep the complete route.
 */
(function () {
    'use strict';

    var routes = Array.prototype.slice.call(document.querySelectorAll('.route[data-animate]'));

    if (routes.length === 0 || !('IntersectionObserver' in window)) {
        return;
    }

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    var seen = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.setAttribute('data-animate', 'on');
                seen.unobserve(entry.target);
            }
        });
    }, { threshold: 0.45 });

    routes.forEach(function (route) {
        route.setAttribute('data-animate', 'ready');
        seen.observe(route);
    });
})();
