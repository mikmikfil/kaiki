<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VatRate;

/**
 * VAT rates, seen from the platform side (spec CAT-11, TEN-5, SEC-3).
 *
 * **Not extending {@see TenantOwnedPolicy}**, for the same reason
 * {@see TenantPolicy} does not: that base gates on the TEN-8 capability matrix
 * and compares `tenant_id`, and a super-admin has neither a role assignment nor
 * a tenant. Inheriting it would make every method return false.
 *
 * ## Super-admin only, at every level
 *
 * An operator never reaches this — not to edit and not to read. They select a
 * rate through the product form, which reads the table directly; the
 * maintenance screen is the platform's. `viewAny` returning false is what keeps
 * the resource out of `/app`'s navigation as well as out of its routes.
 *
 * ## Writable, unlike the merchant list, and why that is not inconsistent
 *
 * {@see TenantPolicy} refuses every write because changing an operator's record
 * is a destructive operator action under SEC-16, and SEC-16's audit trail is
 * what #42's ADR-0025 has not decided yet. Neither half of that applies here: a
 * VAT rate is platform reference data rather than an operator's record, and
 * nothing about adding a rate is destructive.
 *
 * **Deletion is refused outright**, and by the table's own design rather than
 * by caution. §2.3 has no soft deletes: a statutory change is a *new row* with
 * a later `valid_from`, and a rate that is no longer offered is retired with
 * `is_selectable`. Deleting one would orphan every product pointing at it —
 * which `restrictOnDelete` would refuse at the database anyway, so the only
 * thing a delete button could produce is a constraint violation on screen.
 */
class VatRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, VatRate $rate): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Editable, but the form is what stops a rate being rewritten in place —
     * `rate_bp` and `valid_from` are locked once a row exists, so an edit can
     * fix a typo in a description or retire a rate, and cannot silently change
     * what a past product resolved to.
     */
    public function update(User $user, VatRate $rate): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, VatRate $rate): bool
    {
        return false;
    }

    public function restore(User $user, VatRate $rate): bool
    {
        return false;
    }

    public function forceDelete(User $user, VatRate $rate): bool
    {
        return false;
    }
}
