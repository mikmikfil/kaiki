<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Schedule rules (TEN-8: owner and manager, never crew).
 *
 * `ManageCatalogue` rather than `ManagePricing`: a rule decides *when* a trip
 * sails, not what it costs. Crew read the manifest for a departure that already
 * exists; deciding that Tuesdays are now sailing days is not part of standing
 * on the quay.
 */
class ScheduleRulePolicy extends TenantOwnedPolicy
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
