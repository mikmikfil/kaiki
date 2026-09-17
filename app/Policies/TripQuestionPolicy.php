<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * A trip's checkout questions (TEN-8): part of the trip, so whoever manages the
 * catalogue manages them.
 */
class TripQuestionPolicy extends TenantOwnedPolicy
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
