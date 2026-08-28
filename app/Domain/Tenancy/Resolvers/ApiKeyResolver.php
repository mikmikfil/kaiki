<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Resolvers;

use App\Models\ApiKey;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * Strategy 1: an API key in `Authorization: Bearer` or `X-Kaiki-Key`.
 *
 * First in the order because it is the most specific signal a request can
 * carry: the caller has named the tenant explicitly. A request holding a valid
 * key must never be reinterpreted by host or session.
 *
 * This resolves only. `AuthenticateApiKey` (#6) is what rejects a bad key with
 * a specific error code — here an unusable key simply declines, so the chain
 * can move on and, for a non-API route, still fail with a plain 404 rather than
 * leaking that a key exists.
 */
final class ApiKeyResolver implements TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $rawKey = $request->bearerToken() ?: $request->headers->get('X-Kaiki-Key');

        if (! is_string($rawKey) || $rawKey === '') {
            return null;
        }

        $parts = explode('_', $rawKey);
        $random = (int) config('kaiki.api_keys.prefix_random_length');

        if (count($parts) !== 3 || strlen($parts[2]) <= $random) {
            return null;
        }

        $apiKey = ApiKey::findByPrefix("{$parts[0]}_{$parts[1]}_" . substr($parts[2], 0, $random));

        if ($apiKey === null || ! $apiKey->matches($rawKey) || ! $apiKey->isUsable()) {
            return null;
        }

        return Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($apiKey->tenant_id),
        );
    }

    public function name(): string
    {
        return 'api_key';
    }
}
