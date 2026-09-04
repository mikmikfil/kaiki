<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Ports and meeting points (TEN-8: owner and manager, never crew).
 *
 * The same capability as vessels, because they are the same job: `ports` is a
 * catalogue table that products and vessels both point at, and an operator who
 * may edit the fleet may edit the marinas it sails from.
 */
class PortPolicy extends TenantOwnedPolicy
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
