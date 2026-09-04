<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * A season's date ranges.
 *
 * Never authorised on its own — ranges are edited inside their season's
 * repeater — but `PolicyCoverageTest` requires a policy for every tenant-owned
 * model, because Filament allows an action when nothing forbids it. Same
 * capability as the season, so the two cannot drift.
 */
class SeasonDateRangePolicy extends TenantOwnedPolicy
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
