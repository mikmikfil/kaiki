<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Products (TEN-8: owner and manager, never crew).
 *
 * `ManageCatalogue` gates viewing as well as writing, matching {@see VesselPolicy}.
 * Crew are read-only within a departure window and see the pax list and the
 * manifest; the catalogue — what is sold, to how many people, under which
 * cancellation terms — is not part of standing on the quay.
 *
 * Pricing lives behind `ManagePricing` on the rate plans (#21), so the split
 * survives a future role that may edit trips without setting their prices.
 */
class ProductPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageCatalogue;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageCatalogue;
    }
}
