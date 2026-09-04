<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\TenantContextMissingException;
use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Support\Tenancy;

/**
 * BRD-3: a tenant is never without a brand.
 *
 * *"A BrandProfile is created with platform defaults when a tenant is created,
 * so no surface ever renders unbranded."* The requirement says **when a tenant
 * is created**, not when the onboarding wizard finishes — so this is an
 * observer and not a step in an onboarding Action. There are already four
 * paths that create a tenant (the seeder, the factory, `/admin`, and M7's
 * self-serve signup), and a step is something one of them will not do.
 *
 * ## Why `created` and not `creating`
 *
 * The profile holds a foreign key to `tenants.id`, which does not exist until
 * the insert has happened. Laravel wraps `created` in the same transaction as
 * the insert when one is open, so a failure here still leaves no half-made
 * tenant behind.
 *
 * ## Why the tenant scope is suspended
 *
 * The tenant being created is very often **not** the resolved tenant: a
 * super-admin adding an operator in `/admin` has no tenant context at all, and
 * a seeder has whichever one it touched last. `BrandProfile` is tenant-owned,
 * so `firstOrCreate` — which queries before it writes — would either throw
 * {@see TenantContextMissingException} or, worse, look for the
 * new tenant's profile inside somebody else's rows and find nothing there
 * before inserting a row it then cannot see. `tenant_id` is passed explicitly,
 * so nothing is being guessed; the suspension is only about the read.
 */
class TenantObserver
{
    public function created(Tenant $tenant): void
    {
        Tenancy::withoutTenancy(static function () use ($tenant): void {
            // `firstOrCreate` rather than `create`: the unique index on
            // `tenant_id` means a second profile is a constraint violation, and
            // the one caller that can plausibly hit this is a seeder that
            // creates a tenant and then imports a brand for it. Idempotent is
            // cheaper than a 500 nobody can reproduce.
            BrandProfile::query()->firstOrCreate(
                ['tenant_id' => $tenant->getKey()],
                BrandProfile::platformDefaults(),
            );
        });
    }
}
