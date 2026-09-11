<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Filament\App\Pages\CheckIn;
use Illuminate\Http\Response;

/**
 * The service worker that makes the boarding page survive a dead signal
 * (spec OPS-12).
 *
 * ## Served from a route so its scope is the boarding path and nothing else
 *
 * A service worker's scope is the directory it is served from. At
 * `/app/boarding/sw.js` it controls `/app/boarding/…` and cannot touch the rest
 * of the panel — which is the whole point. A worker at the origin root would
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
 * **The POST is never intercepted.** Queueing is the page's job, in IndexedDB
 * where a scan survives the browser being killed; a worker replaying requests
 * from a cache would be a second queue with different semantics and no way for
 * the page to show what is in it.
 */
class BoardingServiceWorkerController
{
    public function __invoke(): Response
    {
        // No page to cache for an operator who does not scan (BKG-20, amended
        // 2026-09-11), so no worker to install.
        abort_unless(CheckIn::qrEnabled(), 404);

        $script = <<<'JS'
        const CACHE = 'kaiki-boarding-v1';

        self.addEventListener('install', (event) => {
            // Take over immediately. A crew member who reloads because the page
            // looked stuck should not have to close every tab first.
            self.skipWaiting();
        });

        self.addEventListener('activate', (event) => {
            event.waitUntil(
                caches.keys()
                    .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
                    .then(() => self.clients.claim())
            );
        });

        self.addEventListener('fetch', (event) => {
            const request = event.request;

            // Only the page itself. A POST is the page's to queue, and anything
            // else in scope is not ours to hold on to.
            if (request.method !== 'GET' || request.mode !== 'navigate') {
                return;
            }

            event.respondWith(
                fetch(request)
                    .then((response) => {
                        // Only a real answer is worth keeping. Caching a 302 to
                        // the login page would put a redirect in front of every
                        // later visit.
                        if (response && response.ok && response.type === 'basic') {
                            const copy = response.clone();
                            caches.open(CACHE).then((cache) => cache.put(request, copy));
                        }
                        return response;
                    })
                    .catch(() => caches.match(request).then((hit) => hit || Response.error()))
            );
        });
        JS;

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Never cached by the browser itself: a stale worker is the one bug
            // in this file nobody can clear without developer tools.
            'Cache-Control' => 'no-store',
        ]);
    }
}
