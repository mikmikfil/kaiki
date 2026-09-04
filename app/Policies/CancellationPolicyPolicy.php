<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Cancellation policies (TEN-8: owner and manager, never crew).
 *
 * `ManagePricing` rather than `ManageCatalogue`: a refund ladder is money, and
 * the TEN-8 matrix separates the two capabilities precisely so that "can edit
 * the boats" and "can change what a cancelling guest is owed" are different
 * answers. They happen to be held by the same two roles today; a future role
 * that manages the catalogue without touching money gets the right answer for
 * free.
 *
 * Crew are refused viewing as well as writing, for the same reason as the rest
 * of the catalogue: they are read-only within a departure window and a refund
 * ladder is not part of standing on the quay with a passenger list.
 */
class CancellationPolicyPolicy extends TenantOwnedPolicy
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
