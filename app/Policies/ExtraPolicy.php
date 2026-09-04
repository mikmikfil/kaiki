<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Extras (TEN-8: owner and manager, never crew).
 *
 * `ManagePricing`: an extra carries a price and its own VAT rate, so it is
 * money rather than catalogue — the same capability as seasons, rate plans and
 * cancellation policies. Crew see the pax list and the manifest, not what a
 * guest was charged for a barbecue.
 */
class ExtraPolicy extends TenantOwnedPolicy
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
