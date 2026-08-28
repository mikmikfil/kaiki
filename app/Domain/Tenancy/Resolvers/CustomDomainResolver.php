<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Resolvers;

use App\Enums\DomainStatus;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Strategy 2: an exact match on a **verified** custom hostname (ADR-0010).
 *
 * Only `status = verified` resolves. A pending or disabled row is not a claim
 * of ownership, and honouring one would let anyone point a hostname at the
 * platform and be served another operator's catalogue.
 *
 * The lookup is cached briefly because it runs on every request to a hosted
 * page, including the hottest public reads. The TTL is short so that disabling
 * a domain takes effect in seconds rather than needing a deploy.
 */
final class CustomDomainResolver implements TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $hostname = TenantDomain::normalise($request->getHost());

        if ($hostname === '' || in_array($hostname, config('tenancy.central_domains', []), strict: true)) {
            return null;
        }

        $ttl = (int) config('kaiki.tenancy.host_cache_seconds');

        $tenantId = Cache::remember(
            "tenant:host:{$hostname}",
            $ttl,
            static fn (): ?int => Tenancy::withoutTenancy(
                static fn (): ?int => TenantDomain::query()
                    ->where('hostname', $hostname)
                    ->where('status', DomainStatus::Verified)
                    ->value('tenant_id'),
            ),
        );

        if ($tenantId === null) {
            return null;
        }

        return Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find((int) $tenantId),
        );
    }

    public function name(): string
    {
        return 'custom_domain';
    }
}
