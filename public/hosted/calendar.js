/**
 * The departures calendar without a reload (Mike, 2026-09-25: «στο ημερολόγιο,
 * τα φίλτρα δεν γίνεται να φορτώνουν με ajax;»).
 *
 * ## It adds, it does not replace
 *
 * Every control on the page is a link — the people −/+, the parts of the day,
 * the trips, «Μόνο με ελεύθερες θέσεις», «Καθαρισμός», the weeks ‹ ›, the
 * «Μήνας» grid — and with this file blocked, missing or never requested each of
 * them still works by navigating (HOS-4). What this adds: the same URL is
 * fetched instead, the server answers with only the calendar's block
 * (`X-Kaiki-Calendar: body`), and that block is swapped in place. The address
 * follows with `history.pushState`, and Back walks the answers backwards.
 *
 * Anything unexpected — a network error, a status that is not 200, an answer
 * without the block — falls back to plain navigation to the same link, so the
 * worst case is the page it would have been without this file.
 *
 * ## The chosen day
 *
 * The strip's days are anchors to their own headings (`#d-2026-09-26`). The
 * chosen one is filled in the operator's colour with `aria-current="date"`:
 * the day pressed, the day in the address's `#d-…` on arrival and on Back, and
 * the first day of the page otherwise — which is today unless the page was
 * moved on with ‹ › or «Μήνας».
 *
 * ## Why a file and not an inline script
 *
 * `script-src 'self'` with no nonce, as `gallery.js` explains: an inline
 * script would be dropped silently. `connect-src 'self'` already admits the
 * fetch.
 */
(function () {
    'use strict';

    if (!document.querySelector('[data-cal]') || !window.fetch || !window.DOMParser
        || !window.history || !window.history.pushState || !Element.prototype.closest) {
        return;
    }

    var DAY = /^#d-(\d{4}-\d{2}-\d{2})$/;
    var rendered = location.pathname + location.search;
    var pending = null;

    function root() {
        return document.querySelector('[data-cal]');
    }

    function dayInHash(hash) {
        var match = DAY.exec(hash || '');

        return match ? match[1] : null;
    }

    /** Fill the chosen day, or the page's first when none (or an unknown one) is named. */
    function selectDay(day) {
        var pills = Array.prototype.slice.call(root().querySelectorAll('.cal-pill'));
        var chosen = pills.filter(function (pill) { return pill.getAttribute('data-day') === day; })[0] || pills[0];

        pills.forEach(function (pill) {
            var on = pill === chosen;

            pill.classList.toggle('is-on', on);

            if (on) {
                pill.setAttribute('aria-current', 'date');
            } else {
                pill.removeAttribute('aria-current');
            }
        });

        // Keep it in sight inside the strip on a phone, without moving the page.
        var strip = chosen && chosen.closest('.cal-pills');

        if (strip) {
            var left = chosen.offsetLeft - strip.offsetLeft;

            if (left < strip.scrollLeft || left + chosen.offsetWidth > strip.scrollLeft + strip.clientWidth) {
                strip.scrollLeft = Math.max(0, left - 8);
            }
        }
    }

    /** When the fortnight moved, bring the list's top back into view — but only if it was scrolled past. */
    function toList() {
        var main = root().querySelector('.cal-main');
        var header = document.querySelector('header.site');

        if (!main) {
            return;
        }

        var top = main.getBoundingClientRect().top;
        var under = header ? header.getBoundingClientRect().height : 0;

        if (top < under) {
            window.scrollTo(0, window.pageYOffset + top - under - 8);
        }
    }

    function load(url, push, link) {
        var current = root();
        var fromBefore = current.getAttribute('data-from');
        var sheetOpen = !!current.querySelector('.cal-sheet[open]');
        var links = Array.prototype.slice.call(current.querySelectorAll('a, summary'));
        var focusAt = link && document.activeElement === link ? links.indexOf(link) : -1;
        var mine = {};

        pending = mine;
        current.setAttribute('aria-busy', 'true');

        fetch(url.pathname + url.search, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Kaiki-Calendar': 'body', Accept: 'text/html' },
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('status ' + response.status);
                }

                return response.text();
            })
            .then(function (html) {
                if (pending !== mine) {
                    return; // A later press already asked for something else.
                }

                var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-cal]');

                if (!fresh) {
                    throw new Error('no calendar in the answer');
                }

                fresh = document.importNode(fresh, true);
                root().replaceWith(fresh);

                // A phone's «Φίλτρα» stays open across a change made inside it.
                if (sheetOpen) {
                    var sheet = fresh.querySelector('.cal-sheet');

                    if (sheet) {
                        sheet.setAttribute('open', '');
                    }
                }

                if (push) {
                    history.pushState({ calendar: true }, '', url.href);
                }

                rendered = url.pathname + url.search;
                selectDay(dayInHash(url.hash));

                if (fresh.getAttribute('data-from') !== fromBefore) {
                    toList();
                }

                if (focusAt >= 0) {
                    var again = fresh.querySelectorAll('a, summary')[focusAt];

                    if (again) {
                        again.focus({ preventScroll: true });
                    }
                }
            })
            .catch(function () {
                if (pending === mine) {
                    location.assign(url.href);
                }
            });
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        var link = event.target.closest('a[href]');
        var cal = root();

        if (!link || !cal || !cal.contains(link) || (link.target && link.target !== '_self')) {
            return;
        }

        // A day of the strip is an anchor on this page: the browser scrolls to
        // it, and the fill moves now, before anything else happens.
        if (link.classList.contains('cal-pill')) {
            selectDay(link.getAttribute('data-day'));

            return;
        }

        var url = new URL(link.href, location.href);

        // Only the calendar's own links; a trip's page is a real navigation.
        if (url.origin !== location.origin || url.pathname !== location.pathname || url.search === location.search) {
            return;
        }

        // A filter keeps the chosen day when the fortnight stays the same.
        var day = dayInHash(location.hash);

        if (day && !url.hash && url.searchParams.get('from') === new URL(location.href).searchParams.get('from')) {
            url.hash = 'd-' + day;
        }

        event.preventDefault();
        load(url, true, link);
    });

    window.addEventListener('popstate', function () {
        if (location.pathname + location.search !== rendered) {
            load(new URL(location.href), false, null);
        } else {
            selectDay(dayInHash(location.hash));
        }
    });

    window.addEventListener('hashchange', function () {
        selectDay(dayInHash(location.hash));
    });

    // So Back to the first answer is recognised as the calendar's own.
    history.replaceState({ calendar: true }, '', location.href);
    selectDay(dayInHash(location.hash));
}());
