<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * An imported external calendar (TEN-8).
 *
 * Same reasoning as {@see IcalFeedPolicy}: the source's URL is frequently a
 * private Airbnb or Google feed token, which is why the column is encrypted —
 * and a role that could read the row could read the credential.
 */
class IcalSourcePolicy extends TenantOwnedPolicy
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
