<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * A single band's price on a plan.
 *
 * Never authorised on its own — prices are edited inside the plan's form — but
 * `PolicyCoverageTest` requires a policy for every tenant-owned model, and a
 * price row is exactly where a missing one would matter: it is the number
 * itself.
 */
class RatePlanPricePolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManagePricing;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManagePricing;
    }
}
