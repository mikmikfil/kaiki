<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Resolvers;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * Strategy 4: the authenticated panel user's own tenant.
 *
 * Last in the order, because a signed-in operator browsing a *different*
 * tenant's hosted page must see that tenant's public pages, not their own back
 * office. Session is the weakest signal about what the request is *for*.
 *
 * The tenant comes from `users.tenant_id` and nowhere else — never from a
 * session value, a query parameter or a header. ADR-0020 Option C gives each
 * user exactly one tenant precisely so this cannot be influenced from outside.
 * A super-admin (`tenant_id` null) resolves nothing and works in the platform
 * panel, which is not tenant-scoped.
 */
final class PanelSessionResolver implements TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $user = $request->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return null;
        }

        return Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($user->tenant_id),
        );
    }

    public function name(): string
    {
        return 'panel_session';
    }
}
