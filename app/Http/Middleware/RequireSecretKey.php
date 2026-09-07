<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a publishable key (spec SEC-5, WPP-3; `docs/api.md` §2.2).
 *
 * Route usage: `->middleware('api.secret')`.
 *
 * One endpoint needs this in v1 — `GET /api/v1/sync/products` — and the reason
 * is what that endpoint returns rather than what it does. The feed carries the
 * whole catalogue including `draft`, `inactive` and `archived` products and the
 * operator's internal SEO fields; it is the one read an unfriendly party would
 * want, and a publishable key sits in the source of somebody's home page.
 *
 * ## Why this is not a scope
 *
 * A scope says what a key may ask for. This says what *kind* of credential may
 * ask at all, which is a different axis: an operator can perfectly well issue a
 * publishable key with `products.read` — that is the default read set — and
 * granting it this feed by adding the scope would be the mistake, not the fix.
 *
 * {@see AuthenticateApiKey} already refuses a secret key sent from a browser.
 * The two together are the whole of ADR-0013 Option A: the secret never reaches
 * a page, and the page's key never reaches the secret's endpoint.
 */
final class RequireSecretKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->attributes->get('api_key');

        if (! $apiKey instanceof ApiKey) {
            // The route is misconfigured: this must run after authentication.
            // A loud failure in development beats an endpoint that serves the
            // full catalogue to whoever asks.
            throw new InvalidArgumentException(
                'RequireSecretKey ran without an authenticated API key. '
                . 'Place it after the api-key authentication middleware on the route.',
            );
        }

        if ($apiKey->type->isPublic()) {
            return ApiErrorResponse::fromKey(
                key: 'api.errors.secret_key_required',
                code: 'secret_key_required',
                status: 403,
                details: ['key_type' => $apiKey->type->value],
            );
        }

        return $next($request);
    }
}
