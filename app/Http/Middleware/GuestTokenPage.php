<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three headers a page whose URL is a credential has to carry (spec TOK-3).
 *
 * > *Token pages are served with `X-Robots-Tag: noindex, nofollow`,
 * > `Cache-Control: no-store`, and a `Referrer-Policy: no-referrer` header so
 * > tokens never leak through referrers to analytics or map providers.*
 *
 * ## The third one is the one that gets forgotten
 *
 * `noindex` stops a search engine and `no-store` stops a shared cache, and both
 * are the obvious two. `no-referrer` is the one that matters most on
 * `/b/{manage_token}`, because that page has a **map link** (TOK-6) — and a
 * guest who taps it sends the whole URL, token and all, to a map provider in
 * the `Referer` header. So do the analytics script, the font CDN and every
 * image on a page that has any. A credential in a URL leaks sideways unless
 * something says not to.
 *
 * ## Middleware rather than four controllers remembering
 *
 * A header set in a controller is a header the fifth token page forgets.
 * `TokenSecurityTest` asserts all three on every route in the group, including
 * the failure page — which is the one an implementation is most likely to build
 * outside the group, because it is "just an error".
 */
class GuestTokenPage
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Set with `->set()` rather than `->add()`: a framework default for any
        // of the three would otherwise survive alongside ours, and two
        // `Cache-Control` values is an argument a proxy settles for itself.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
