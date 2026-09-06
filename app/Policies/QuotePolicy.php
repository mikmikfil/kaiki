<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Quotes (TEN-8: `ManagePricing` to write, `ViewFinancials` to read).
 *
 * ## Writing a quote is a pricing decision, not a booking one
 *
 * Building a quote means choosing what a charter costs — `ManagePricing`, the
 * same permission that governs rate plans and seasons. `ManageBookings` would
 * let anybody who can cancel a booking also decide what a boat is worth, and
 * those are different jobs in every operator this product is for.
 *
 * ## Crew do not read them either, and {@see PaymentPolicy} gives the reason
 *
 * *"A payment row tells them what each guest paid, which is not their business
 * and is the kind of thing that ends up discussed on a quay."* A quote is that
 * sentence in advance: what the operator asked for, what they discounted, and
 * what the party agreed to. `ViewPaxList` would have been the convenient
 * reading — crew meet the party, after all — and it would put the operator's
 * commercial terms on a phone on a pier.
 */
class QuotePolicy extends TenantOwnedPolicy
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
