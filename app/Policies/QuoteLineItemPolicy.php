<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * The lines on a quote (TEN-8), governed exactly as the quote is.
 *
 * A separate class rather than none, because `PolicyCoverageTest` is right to
 * insist: Filament allows an action when no policy is registered, and a line
 * item is reachable through a relation manager without a resource of its own.
 * A model that is writable because nobody thought about it is the failure SEC-3
 * exists to prevent.
 *
 * The capabilities match {@see QuotePolicy} deliberately. A line **is** the
 * price — an authorisation that let somebody read or edit the lines but not the
 * quote would be a way round the policy that governs the total.
 */
class QuoteLineItemPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewFinancials;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManagePricing;
    }
}
