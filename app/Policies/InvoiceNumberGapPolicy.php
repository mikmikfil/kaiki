<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;

/**
 * The gap log — read by anyone who may see invoices, written by the system.
 *
 * MYD-4.4 requires gaps to be logged and surfaced in Greek. It does not require
 * them to be editable, and they must not be: a gap that a person can amend or
 * delete is a gap that will be, on the afternoon it looks embarrassing, which
 * is the one afternoon the record matters.
 */
class InvoiceNumberGapPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ViewFinancials;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageBilling;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, mixed $record): bool
    {
        return false;
    }

    public function delete(User $user, mixed $record): bool
    {
        return false;
    }
}
