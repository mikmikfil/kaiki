<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Vessels (TEN-8: owner and manager, never crew).
 *
 * `ManageCatalogue` gates **viewing** as well as writing, which is what refuses
 * crew. That is deliberate and not an oversight: crew are read-only within a
 * departure window and see the pax list and manifest, and a fleet's
 * registration numbers, captain names and legal capacities are not part of
 * standing on the quay with a passenger list.
 */
class VesselPolicy extends TenantOwnedPolicy
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
