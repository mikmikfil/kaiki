<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Seasons (TEN-8: owner and manager, never crew).
 *
 * `ManagePricing` rather than `ManageCatalogue`: a season decides which prices
 * apply, so it is money rather than catalogue. Same capability as cancellation
 * policies and, later, rate plans — the three things that decide what a guest
 * is charged sit behind one permission.
 */
class SeasonPolicy extends TenantOwnedPolicy
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
