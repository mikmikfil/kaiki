<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS from the key's own allow-list (spec SEC-7, `docs/api.md` §3.7).
 *
 * ## Per key, not per application
 *
 * Laravel's own CORS config is one list for the whole app, which is exactly
 * wrong here: an operator's publishable key belongs on their site and nowhere
 * else, and one shared allow-list would let any tenant's key be used from any
 * tenant's page. `api_keys.allowed_origins` is the list, and this middleware is
 * where it becomes a header.
 *
 * ## This adds headers; it does not do the refusing
 *
 * `AuthenticateApiKey` (#6) already refuses an unlisted origin with a **403
 * `origin_not_allowed`**, before this ever runs — the allow-list is enforced
 * server-side rather than left to the browser, which is the stronger of the two
 * and the one an attacker cannot skip by not being a browser.
 *
 * So by the time a request reaches here the origin is known-good, and all this
 * does is tell the browser so. The two layers are complementary: without the
 * 403 a non-browser client would sail past the allow-list entirely, and without
 * these headers a legitimate widget could not read the response it was allowed
 * to make.
 *
 * An empty allow-list permits any origin — the documented default for a key
 * nobody has narrowed yet, which the panel warns about (#10).
 *
 * ## Global, because a preflight carries no key
 *
 * Registered globally rather than on the route group, for one reason: a browser
 * **does not send `Authorization` on a preflight**. There is no key to check,
 * so the answer cannot depend on one — and a route-scoped middleware would not
 * run at all, because an `OPTIONS` request with no matching route is a 405
 * before any group middleware executes.
 *
 * Answering a preflight permissively is safe here precisely because it decides
 * nothing: the real request that follows carries the key and is refused by
 * `AuthenticateApiKey` if its origin is not on the list. The preflight only
 * tells the browser which headers it may send.
 */
final class ApiKeyCors
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only the public API. This runs globally, and the panel has its own
        // same-origin session — CORS headers there would be noise at best.
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');

        // A preflight is answered here and never routed: with no key it cannot
        // be authenticated, and with no matching `OPTIONS` route it would be a
        // 405 before any group middleware ran.
        $isPreflight = $request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method');

        $response = $isPreflight
            ? response()->noContent(Response::HTTP_NO_CONTENT)
            : $next($request);

        if ($origin === null) {
            return $response;
        }

        $key = $request->attributes->get('api_key');

        // On the actual request the key has been resolved by the time this sees
        // the response, so the allow-list still decides. On a preflight there
        // is no key and nothing to decide — see the class docblock.
        if (! $isPreflight && (! $key instanceof ApiKey || ! $key->allowsOrigin($origin))) {
            return $response;
        }

        // The exact origin rather than `*`: a wildcard cannot carry credentials
        // and tells every other site it is welcome too.
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        // Merged, not set. This middleware is prepended globally, so it is the
        // last thing to touch the response — overwriting here would drop the
        // `Accept-Language` and `X-Kaiki-Key` that `SetLocale` added, and a
        // shared cache would start serving one operator's Greek payload to
        // another's English page (`docs/api.md` §3.1).
        $response->headers->set('Vary', implode(', ', array_values(array_unique([
            ...array_filter(array_map(trim(...), explode(',', (string) $response->headers->get('Vary', '')))),
            'Origin',
        ]))));
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, Accept-Language, If-None-Match');
        $response->headers->set('Access-Control-Expose-Headers', 'ETag, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After');
        $response->headers->set('Access-Control-Max-Age', '600');

        return $response;
    }
}
