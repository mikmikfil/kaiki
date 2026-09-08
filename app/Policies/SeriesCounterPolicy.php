<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Compliance\Actions\AllocateInvoiceNumber;
use App\Models\User;
use App\Support\Authorization\Capability;

/**
 * The numbering counter — readable by whoever may see invoices, writable by
 * nobody.
 *
 * There is no screen for this and there must not be one. Editing the last
 * allocated number by hand is how a series gains a duplicate or a gap that
 * nothing recorded, and both are exactly what
 * {@see AllocateInvoiceNumber} exists to prevent.
 * The counter moves through allocation or not at all.
 */
class SeriesCounterPolicy extends TenantOwnedPolicy
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
