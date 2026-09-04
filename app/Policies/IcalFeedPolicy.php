<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * The iCal export feed (TEN-8).
 *
 * `ManageCatalogue` to read as well as to write, and the reason is the token:
 * the feed's URL **is** its credential, so "viewing" a feed row means seeing
 * the secret that opens an operator's whole calendar. There is no read-only
 * version of that.
 */
class IcalFeedPolicy extends TenantOwnedPolicy
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
