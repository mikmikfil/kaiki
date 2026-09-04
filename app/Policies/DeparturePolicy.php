<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Departures (TEN-8).
 *
 * **`ViewDepartures` to read, `ManageCatalogue` to write** — the first policy
 * where the two differ, and deliberately: crew hold `ViewDepartures` because
 * standing on the quay with a passenger list is their entire job, while
 * creating or cancelling a sailing is the operator's decision.
 *
 * It exists now because `PolicyCoverageTest` requires one for every
 * tenant-owned model, and a departure is exactly where a missing policy would
 * matter — Filament allows what nothing forbids.
 */
class DeparturePolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewDepartures;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageCatalogue;
    }
}
