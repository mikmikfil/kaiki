<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Rate plans (TEN-8: owner and manager, never crew).
 *
 * `ManagePricing` — the third of the three things that decide what a guest is
 * charged, behind the same permission as seasons and cancellation policies.
 * Crew get the manifest; what a seat cost is not on it.
 */
class RatePlanPolicy extends TenantOwnedPolicy
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
