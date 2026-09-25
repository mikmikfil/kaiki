<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use Illuminate\Http\Response;

/**
 * The service worker that makes the boarding page survive a dead signal
 * (spec OPS-12).
 *
 * ## Served from a route so its scope is the boarding path and nothing else
 *
 * A service worker's scope is at most the directory it is served from. At
 * `/app/boarding/sw.js` that is `/app/boarding/…` — which leaves out the page
 * itself, `/app/boarding`, so the worker is served with
 * `Service-Worker-Allowed: /app/boarding` and registered with exactly that
 * scope ({@see self::scope()}). Either way it cannot touch the rest of the
 * panel — which is the whole point. A worker at the origin root would
 * cache authenticated HTML, and a phone handed to somebody else after a crew
 * member signed out would still be able to render the last operator's screens.
 *
 * ## It caches the page and refuses to cache anything else
 *
 * One document, network-first. Network-first rather than cache-first because a
 * stale manifest is worse than a slow one: the page embeds the day's guest list
 * in its own bytes, so a cached copy from yesterday morning is a boarding list
 * for yesterday's boat. Online, the crew always get today's.
 *
 * The page is kept under its path **without the query**. `?ticket=…` (a phone's
 * own camera opening a ticket) and `?camera=1` (the home page's scan button)
 * are read by the page's script, not by the server, so every one of them is the
 * same document — and one opened with no signal must still find it.
 *
 * ## And the camera script, which is not a page
 *
 * The in-page scanner is a built file under `/build/assets/`, outside this
 * worker's scope — but scope only decides which *pages* a worker controls; a
 * controlled page's requests all pass through it. The file's hashed URL is
 * written into this script, so a new build is a new worker, which precaches
 * the new file on install and drops the old one on activate. Cache-first: a
 * hashed file never changes under its name.
 *
 * **The POST is never intercepted.** Queueing is the page's job, in IndexedDB
 * where a scan survives the browser being killed; a worker replaying requests
 * from a cache would be a second queue with different semantics and no way for
 * the page to show what is in it.
 *
 * ## It shares the origin with the panel's worker
 *
 * `/app` has a worker too ({@see PanelServiceWorkerController}), with the
 * shorter scope, so this one keeps `/app/boarding`. Cache Storage is one per
 * origin, though, so on activate this worker deletes only caches named
 * `kaiki-boarding…` — until 2026-09-23 it deleted everything but its own two,
 * which would have taken the panel's offline page with it.
 */
class BoardingServiceWorkerController
{
    public function __invoke(): Response
    {
        $assets = array_values(array_filter([BoardingController::scannerScriptUrl()]));

        $script = 'const ASSETS = ' . json_encode($assets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n"
            . 'const PAGE = ' . json_encode(route('filament.app.boarding'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n"
            . <<<'JS'
        const CACHE = 'kaiki-boarding-v2';
        const ASSET_CACHE = 'kaiki-boarding-assets';
        const ASSET_URLS = ASSETS.map((url) => new URL(url, self.location.href).href);
        const PAGE_URL = new URL(PAGE, self.location.href);
        const PAGE_KEY = PAGE_URL.origin + PAGE_URL.pathname;

        self.addEventListener('install', (event) => {
            // Take over immediately. A crew member who reloads because the page
            // looked stuck should not have to close every tab first.
            self.skipWaiting();

            event.waitUntil(Promise.all([
                // The camera script, fetched now rather than on first use: the
                // first time somebody opens the camera may be on the boat.
                caches.open(ASSET_CACHE).then((cache) => Promise.all(
                    ASSET_URLS.map((url) => cache.add(url).catch(() => null))
                )),
                // And the page. The load that registered this worker happened
                // before the worker existed, so nothing kept it; without this
                // the first reload with no signal would find an empty cache.
                // `manual`: a redirect to the login page is not the page.
                fetch(PAGE_KEY, { credentials: 'same-origin', redirect: 'manual' })
                    .then((response) => (response.ok && response.type === 'basic'
                        ? caches.open(CACHE).then((cache) => cache.put(PAGE_KEY, response))
                        : null))
                    .catch(() => null),
            ]));
        });

        self.addEventListener('activate', (event) => {
            event.waitUntil(
                caches.keys()
                    // Only our own old ones: the panel's worker keeps its
                    // caches in the same storage (`kaiki-panel-…`), and they
                    // are not this worker's to clear.
                    .then((keys) => Promise.all(keys.filter((k) => k.startsWith('kaiki-boarding') && k !== CACHE && k !== ASSET_CACHE).map((k) => caches.delete(k))))
                    // Last build's camera script, which no page asks for any more.
                    .then(() => caches.open(ASSET_CACHE))
                    .then((cache) => cache.keys().then((requests) => Promise.all(
                        requests.filter((r) => !ASSET_URLS.includes(r.url)).map((r) => cache.delete(r))
                    )))
                    .then(() => self.clients.claim())
            );
        });

        self.addEventListener('fetch', (event) => {
            const request = event.request;

            if (request.method !== 'GET') {
                return;
            }

            if (ASSET_URLS.includes(request.url)) {
                event.respondWith(
                    caches.open(ASSET_CACHE).then((cache) => cache.match(request.url).then((hit) => hit || fetch(request).then((response) => {
                        if (response && response.ok) {
                            cache.put(request.url, response.clone());
                        }
                        return response;
                    })))
                );
                return;
            }

            // Otherwise only the page itself. A POST is the page's to queue,
            // and anything else in scope is not ours to hold on to.
            if (request.mode !== 'navigate') {
                return;
            }

            const url = new URL(request.url);
            const key = url.origin + url.pathname;

            event.respondWith(
                fetch(request)
                    .then((response) => {
                        // Only a real answer is worth keeping. Caching a 302 to
                        // the login page would put a redirect in front of every
                        // later visit.
                        if (response && response.ok && response.type === 'basic') {
                            const copy = response.clone();
                            caches.open(CACHE).then((cache) => cache.put(key, copy));
                        }
                        return response;
                    })
                    .catch(() => caches.open(CACHE).then((cache) => cache.match(key)).then((hit) => hit || Response.error()))
            );
        });
        JS;

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // The page is `/app/boarding`, and a worker served from
            // `/app/boarding/sw.js` may only claim `/app/boarding/` — which
            // the page itself is *not* under, so it was never controlled and
            // never worked offline. This allows exactly the page's own path
            // as the scope, and nothing wider.
            'Service-Worker-Allowed' => self::scope(),
            // Never cached by the browser itself: a stale worker is the one bug
            // in this file nobody can clear without developer tools.
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The worker's scope: the boarding page's own path, `/app/boarding`.
     *
     * A prefix, so it covers `/app/boarding`, its query strings and
     * `/app/boarding/…`, and no other screen of the panel.
     */
    public static function scope(): string
    {
        return (string) parse_url(route('filament.app.boarding'), PHP_URL_PATH);
    }
}
