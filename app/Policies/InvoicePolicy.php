<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Authorization\Capability;

/**
 * Invoices — money, so `ViewFinancials`; and never deletable by anybody.
 *
 * TEN-8 keeps pricing and financials away from crew, and an invoice is the most
 * financial row in the product: totals, VAT, a counterparty ΑΦΜ. The manager
 * keeps it, because chasing an invoice that AADE refused is office work rather
 * than an owner's decision.
 *
 * ## `delete` is false for everyone, including the owner
 *
 * `docs/data-model.md` §1.4 puts invoices among the rows no code path removes.
 * A document in a tax register is corrected by another document — a credit note
 * — and never by a `DELETE`. The policy says so out loud rather than relying on
 * no screen offering the button, because the next screen might.
 */
class InvoicePolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewFinancials;
    }

    /**
     * Issuing, retrying and raising a credit note.
     *
     * `ManageBookings` rather than `ViewFinancials`: reading an invoice is
     * looking at money, but issuing one puts a document in a state register in
     * the operator's name, which is the heavier of the two.
     */
    protected function manageCapability(): Capability
    {
        return Capability::ManageBookings;
    }

    public function delete(User $user, mixed $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, mixed $record): bool
    {
        return false;
    }

    public function restore(User $user, mixed $record): bool
    {
        return false;
    }
}
