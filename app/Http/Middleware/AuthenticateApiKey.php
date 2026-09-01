<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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

        $prefix = $this->prefixOf($rawKey);

        if ($prefix === null) {
            return $this->reject('api.errors.malformed_key', 'malformed_key', 401);
        }

        // One indexed lookup by prefix, then a constant-time comparison. Never
        // a scan, and never a comparison that short-circuits on first mismatch.
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

        // SEC-5(3): browsers always send Origin cross-origin, so a secret key
        // arriving with one means it has been put somewhere it must never be.
        // Failing loudly is the point — quiet success would mean an operator
        // shipping their secret key in a web page and never finding out.
        if (! $apiKey->type->isPublic() && $request->headers->has('Origin')) {
            return $this->reject('api.errors.secret_key_from_browser', 'secret_key_from_browser', 403);
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

        $apiKey->touchLastUsed();

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
