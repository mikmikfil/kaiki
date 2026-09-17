<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Discount codes (TEN-8): money off a price, so whoever manages pricing
 * manages them — the same capability as vouchers' neighbours, rate plans and
 * extras. Crew never see them.
 */
class DiscountCodePolicy extends TenantOwnedPolicy
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
