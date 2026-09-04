<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * The product-extra pivot.
 *
 * Never authorised on its own — overrides are edited from either side of the relationship —
 * but `PolicyCoverageTest` requires a policy for every tenant-owned model, and
 * a pivot is exactly where a missing one would go unnoticed: nothing about a
 * join table looks like a leak.
 */
class ProductExtraPolicy extends TenantOwnedPolicy
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
