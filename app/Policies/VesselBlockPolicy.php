<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Vessel blocks (TEN-8: owner and manager, never crew).
 *
 * `ManageCatalogue`: taking a boat out of service decides what can be sold, so
 * it belongs with the catalogue rather than with pricing. Crew read the
 * manifest for a sailing that exists; declaring the boat unavailable next
 * Tuesday is not part of that.
 */
class VesselBlockPolicy extends TenantOwnedPolicy
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
