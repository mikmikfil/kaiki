<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Resolvers;

use App\Domain\Hosted\Support\HostedHost;
use App\Enums\HostedSiteMode;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Strategy 3: `book.{platform-domain}/{slug}` — the first path segment.
 *
 * Only applies on the hosted-page host. Reading the first path segment on any
 * host would turn every unmatched URL into a tenant lookup, and `/login` would
 * resolve an operator called "login" the day someone registers that slug.
 *
 * The **off** state declines rather than resolving, so the chain
 * ends in a plain 404 (HOS-6). An operator who has turned their hosted page off
 * should look switched off, not broken.
 */
final class HostedSlugResolver implements TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $host = strtolower($request->getHost());
        $hostedHost = HostedHost::name();

        if ($hostedHost === '' || $host !== $hostedHost) {
            return null;
        }

        $slug = $request->segment(1);

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $ttl = (int) config('kaiki.tenancy.host_cache_seconds');

        $tenantId = Cache::remember(
            "tenant:slug:{$slug}",
            $ttl,
            static fn (): ?int => Tenancy::withoutTenancy(
                static fn (): ?int => Tenant::query()
                    ->where('slug', $slug)
                    // The coarse question, and it is the only one this can
                    // ask: it runs before routing, so there is no route yet and
                    // therefore no page to ask about. A *bookings only* operator
                    // resolves here and 404s later, in the one controller that
                    // knows which page was wanted (ADR-0029).
                    ->whereIn('hosted_site_mode', HostedSiteMode::servesAnythingValues())
                    ->value('id'),
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
        return 'hosted_slug';
    }
}
