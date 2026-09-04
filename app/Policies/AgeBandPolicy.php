<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Age bands (TEN-8: owner and manager, never crew).
 *
 * Same capability as the product they belong to — bands are edited inside a
 * product's form and have no screen of their own, so a different answer here
 * would only ever be a way for the two to disagree.
 *
 * It exists at all because `PolicyCoverageTest` requires one for every
 * tenant-owned model: Filament allows an action when nothing forbids it, so a
 * model reachable through a relation manager with no policy is writable by
 * every role and nothing in the code says so.
 */
class AgeBandPolicy extends TenantOwnedPolicy
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
