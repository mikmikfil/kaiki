<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Hosted\Support\HostedEmbedToken;
use App\Http\Responses\ApiErrorResponse;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a presented API key into a resolved tenant (spec TEN-3, ARC-6).
 *
 * This is strategy (1) of the four in TEN-4; #7 adds custom domain, hosted slug
 * and panel session around it. Everything here fails closed: any doubt about a
 * key ends the request rather than continuing without a tenant.
 */
final class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = $this->extractKey($request);

        if ($rawKey === null) {
            return $this->reject('api.errors.missing_key', 'missing_key', 401);
        }

        // A hosted page's own token, which is not a stored key and has no
        // prefix to look up. It resolves to an unsaved publishable `ApiKey`, so
        // every check below this point — and every middleware after it — reads
        // the same object it always did. See HostedEmbedToken.
        if (HostedEmbedToken::looksLikeOne($rawKey)) {
            $apiKey = HostedEmbedToken::resolve($rawKey, $this->requestOrigins($request));

            if ($apiKey === null) {
                // One answer for a bad signature, an expired token and a
                // hosted page since switched off. Telling them apart would
                // tell an attacker which half they got right.
                return $this->reject('api.errors.invalid_key', 'invalid_key', 401);
            }
        } else {
            $prefix = $this->prefixOf($rawKey);

            if ($prefix === null) {
                return $this->reject('api.errors.malformed_key', 'malformed_key', 401);
            }

            // One indexed lookup by prefix, then a constant-time comparison.
            // Never a scan, and never a comparison that short-circuits on first
            // mismatch.
            $apiKey = ApiKey::findByPrefix($prefix);

            if ($apiKey === null || ! $apiKey->matches($rawKey)) {
                return $this->reject('api.errors.invalid_key', 'invalid_key', 401);
            }

            if ($apiKey->isRevoked()) {
                return $this->reject('api.errors.revoked_key', 'revoked_key', 401);
            }

            if ($apiKey->isExpired()) {
                return $this->reject('api.errors.expired_key', 'expired_key', 401);
            }
        }

        // SEC-5(3): browsers always send Origin cross-origin, so a secret key
        // arriving with one means it has been put somewhere it must never be.
        // Failing loudly is the point — quiet success would mean an operator
        // shipping their secret key in a web page and never finding out.
        if (! $apiKey->type->isPublic() && $request->headers->has('Origin')) {
            // The code is `secret_key_in_browser`, spelled the way §4.2 spells
            // it. Clients branch on `error.code` and never on `message`, so the
            // table in the contract is the name — a synonym here is a client
            // that handles the case in the document and not the one in the wire.
            return ApiErrorResponse::fromKey(
                key: 'api.errors.secret_key_in_browser',
                code: 'secret_key_in_browser',
                status: 403,
                details: ['origin' => (string) $request->headers->get('Origin')],
            );
        }

        if ($apiKey->type->isPublic() && ! $apiKey->allowsOrigin($request->headers->get('Origin'))) {
            return $this->reject('api.errors.origin_not_allowed', 'origin_not_allowed', 403);
        }

        $tenant = $apiKey->tenant()->withoutGlobalScopes()->first();

        if ($tenant === null) {
            return $this->reject('api.errors.invalid_key', 'invalid_key', 401);
        }

        tenancy()->initialize($tenant);

        $request->attributes->set('api_key', $apiKey);

        // Only for a key that is actually a row. A hosted page's token is
        // minted per response and has nothing to stamp.
        if ($apiKey->exists) {
            $apiKey->touchLastUsed();
        }

        return $next($request);
    }

    /** `Authorization: Bearer <key>`, or the `X-Kaiki-Key` header the widget uses. */
    private function extractKey(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $header = $request->headers->get('X-Kaiki-Key');

        return is_string($header) && $header !== '' ? $header : null;
    }

    /**
     * The origins a hosted page's token is allowed to be presented from.
     *
     * The request's own `Origin`, and nothing else. The page and the API are on
     * the same platform, so a token minted by a hosted page is used from that
     * page — a token lifted out of one page's source and replayed from
     * somewhere else fails the origin check that every publishable key already
     * runs. A request with no `Origin` at all (curl, a crawler) gets an empty
     * list, which `allowsOrigin()` reads as unrestricted; that matches how a
     * publishable key with no allow-list behaves and is the same exposure.
     *
     * @return list<string>
     */
    private function requestOrigins(Request $request): array
    {
        $origin = $request->headers->get('Origin');

        return is_string($origin) && $origin !== '' ? [$origin] : [];
    }

    /** `pk_live_a1b2c3xxxx…` becomes `pk_live_a1b2c3`. */
    private function prefixOf(string $rawKey): ?string
    {
        $parts = explode('_', $rawKey);

        if (count($parts) !== 3 || $parts[2] === '') {
            return null;
        }

        $random = (int) config('kaiki.api_keys.prefix_random_length');

        if (strlen($parts[2]) <= $random) {
            return null;
        }

        return "{$parts[0]}_{$parts[1]}_" . substr($parts[2], 0, $random);
    }

    /**
     * Errors are translated, never raw exception text (CNV-11).
     *
     * Both locales are returned so the client picks — a widget rendering in
     * Greek must not round-trip to find out what went wrong.
     */
    private function reject(string $key, string $code, int $status): JsonResponse
    {
        return ApiErrorResponse::fromKey($key, $code, $status);
    }
}
