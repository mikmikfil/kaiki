<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Support\PanelApp;
use Illuminate\Http\Response;

/**
 * The panel's service worker (PWA, 2026-09-23): an offline page, and the
 * panel's own versioned files kept on the phone. Nothing else.
 *
 * ## What it never keeps: a page
 *
 * Every screen of the panel is somebody's bookings, guests and takings, and a
 * phone on a boat is handed from one crew member to the next. A cached screen
 * would render for whoever holds the phone after the last person signed out,
 * so navigations go to the network every time and their answers are passed
 * through untouched. With no network the answer is one page, the same for
 * everybody and about nobody: «Χωρίς σύνδεση», with a way to try again and a
 * way to the boarding page, which *does* work offline (see below). Livewire's
 * updates, the API and every other request are not touched at all.
 *
 * ## What it keeps: files whose name changes when they do
 *
 * Vite's hashed bundles under `/build/assets/`, and Filament's CSS and scripts,
 * the panel theme and the app icons when their URL carries a `?v=` — cache
 * first, since a file under a versioned name never changes. An unversioned URL
 * is left to the network, so nothing can go stale under a name that stays put.
 * The cache is named after the build ({@see PanelApp::version()}), and a new
 * build's worker deletes the last one's when it takes over.
 *
 * ## Two workers, one origin
 *
 * `/app/boarding` has a worker of its own ({@see BoardingServiceWorkerController}),
 * which keeps the boarding page and the day's list for a quay with no signal.
 * Its scope, `/app/boarding`, is longer than this one's, `/app`, and the
 * longest scope wins: the boarding page stays the boarding worker's, and this
 * one never sees it. The Cache Storage is shared between them, though, so each
 * deletes only caches with its own prefix — `kaiki-panel-` here.
 *
 * ## Served from `/app/sw.js`, allowed `/app`
 *
 * A worker's scope is at most its own directory, `/app/`, which leaves out the
 * dashboard at `/app`. `Service-Worker-Allowed` widens it by exactly that, and
 * the page registers it with that scope. Outside the sign-in wall, so the
 * sign-in page registers it too and the offline page is on the phone before
 * the first password is typed.
 */
class PanelServiceWorkerController
{
    public function __invoke(): Response
    {
        $source = <<<'JS'
        const CACHE_PREFIX = 'kaiki-panel-';
        const CACHE = CACHE_PREFIX + VERSION;
        const OFFLINE_URL = new URL(OFFLINE, self.location.href).href;

        // Kept only when versioned: the name changes whenever the file does.
        const VERSIONED = ['/css/filament/', '/js/filament/', '/fonts/filament/', '/images/app-icon/'];

        function isVersionedAsset(url) {
            if (url.origin !== self.location.origin) {
                return false;
            }
            // Vite's own names carry the hash.
            if (url.pathname.startsWith('/build/assets/')) {
                return true;
            }
            return url.searchParams.has('v') && VERSIONED.some((prefix) => url.pathname.startsWith(prefix));
        }

        // The offline page, fetched with the session's cookie so it comes back in
        // the operator's language. `manual`: a redirect is not the page.
        function refreshOfflinePage() {
            return fetch(OFFLINE_URL, { credentials: 'same-origin', redirect: 'manual', cache: 'no-store' })
                .then((response) => (response.ok && response.type === 'basic'
                    ? caches.open(CACHE).then((cache) => cache.put(OFFLINE_URL, response))
                    : null))
                .catch(() => null);
        }

        self.addEventListener('install', (event) => {
            self.skipWaiting();
            event.waitUntil(refreshOfflinePage());
        });

        self.addEventListener('activate', (event) => {
            event.waitUntil((async () => {
                // Last build's files. Only ours: the boarding worker's caches
                // live in the same storage and are not this worker's to clear.
                const keys = await caches.keys();
                await Promise.all(keys
                    .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE)
                    .map((key) => caches.delete(key)));

                // The page's request starts while the worker is still waking.
                if (self.registration.navigationPreload) {
                    await self.registration.navigationPreload.enable();
                }

                await self.clients.claim();
            })());
        });

        // The page says which language it is in; the offline page follows it.
        self.addEventListener('message', (event) => {
            const data = event.data || {};

            if (data.type !== 'kaiki-locale' || typeof data.lang !== 'string') {
                return;
            }

            event.waitUntil(caches.open(CACHE)
                .then((cache) => cache.match(OFFLINE_URL))
                .then((hit) => (hit && hit.headers.get('Content-Language') === data.lang ? null : refreshOfflinePage())));
        });

        self.addEventListener('fetch', (event) => {
            const request = event.request;

            if (request.method !== 'GET') {
                return;
            }

            if (request.mode === 'navigate') {
                // Network, always, and the answer passed on without a copy kept.
                event.respondWith((async () => {
                    try {
                        const preloaded = await event.preloadResponse;

                        return preloaded || await fetch(request);
                    } catch (error) {
                        const offline = await caches.match(OFFLINE_URL, { cacheName: CACHE });

                        return offline || Response.error();
                    }
                })());
                return;
            }

            const url = new URL(request.url);

            if (!isVersionedAsset(url)) {
                // Livewire, the API, uploads, exports, anything without a
                // version in its name: the browser's, untouched.
                return;
            }

            event.respondWith(caches.open(CACHE).then((cache) => cache.match(request).then((hit) => hit || fetch(request).then((response) => {
                if (response && response.ok && response.type === 'basic') {
                    cache.put(request, response.clone());
                }
                return response;
            }))));
        });
        JS;

        $script = 'const VERSION = ' . json_encode(PanelApp::version($source), JSON_THROW_ON_ERROR) . ";\n"
            . 'const OFFLINE = ' . json_encode(PanelApp::path(route('filament.app.offline')), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n"
            . $source;

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Served from `/app/`, registered for `/app`: see the class comment.
            'Service-Worker-Allowed' => PanelApp::scope(),
            // A stale worker is the one bug nobody can clear without developer
            // tools; the browser must always ask for this file.
            'Cache-Control' => 'no-store',
        ]);
    }
}
