<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PolicyTemplate;
use App\Models\User;

/**
 * The cancellation ladders offered at setup, seen from the platform side.
 *
 * The same shape as {@see VatRatePolicy}, and for the same reasons: not
 * extending {@see TenantOwnedPolicy}, because that base compares `tenant_id`
 * and gates on the TEN-8 capability matrix, and a super-admin has neither.
 *
 * ## Super-admin only, including the read
 *
 * An operator never reaches this table. They see three cards in the setup
 * guide, which the guide draws itself — so `viewAny` returning false keeps the
 * screen out of `/app`'s navigation and its routes without taking anything away
 * from an operator.
 *
 * ## Deletable, unlike a VAT rate — but only while unused
 *
 * A VAT rate can never be deleted because products point at it. Nothing points
 * at a template: choosing one **copies** it into the operator's own
 * `cancellation_policies` row, so deleting a template cannot orphan anything
 * and cannot change terms an operator already published.
 *
 * Retiring is still the better move and is what the screen leads with
 * (`is_active`), because a code that disappears makes the seeder recreate the
 * row on the next `db:seed` — the three shipped ladders come back. Delete is
 * for a fourth one added by mistake.
 */
class PolicyTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, PolicyTemplate $template): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, PolicyTemplate $template): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, PolicyTemplate $template): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, PolicyTemplate $template): bool
    {
        return false;
    }

    public function forceDelete(User $user, PolicyTemplate $template): bool
    {
        return false;
    }
}
