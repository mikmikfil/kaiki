<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\Actions\OnboardOperator;
use App\Models\Tenant;
use App\Models\User;

/**
 * The operator record itself, seen from the platform side (SAA-1, SCP-13).
 *
 * **Deliberately not extending {@see TenantOwnedPolicy}.** Every other policy
 * does, but that base gates on the TEN-8 `Capability` matrix and compares
 * `tenant_id` — and `tenants` is not tenant-owned, it *is* the tenant. A
 * super-admin has no role assignment and a null `tenant_id`, so inheriting it
 * would make every method return false. Consistency that produces the wrong
 * answer is not consistency.
 *
 * **`update` is allowed; everything else still is not.**
 *
 * This screen was read-only for a stated reason: SEC-16 requires a platform
 * write to be *"confirmed and audit-logged with actor, timestamp and reason"*,
 * and when the merchant list shipped the audit log was an undecided ADR. That
 * has not been true since **2026-09-04** — ADR-0025 was accepted and #53 built
 * `audit_logs`, its listeners and the operator's own trail — and the comment
 * here outlived the blocker by five days. The gate was still closed because
 * nobody re-read the reason it was closed for.
 *
 * So editing an operator's plan, status, lapse date, sandbox flag and trade is
 * allowed now, and `EditTenant` supplies all three of SEC-16's requirements: a
 * confirmation, an entry in the operator's trail, and a **required** reason.
 *
 * `delete` stays refused, and not out of caution: it is `Tenant`'s soft delete
 * plus seven years of invoices and audit rows that must not go with it, which
 * is a data-retention decision (GDR, ADR-0012) rather than a button.
 *
 * The refusals are asserted rather than left implicit: Filament allows an
 * action when nothing forbids it, so "we did not build a page" is not the same
 * as "it is refused". The next person to add one would otherwise get it free.
 *
 * Impersonation (TEN-7) is still M7 and still blocked on its own decision —
 * ADR-0024 (two-factor) is **Proposed**, not accepted.
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Onboarding, and it is a super-admin form after all.
     *
     * This returned false with the comment *"onboarding creates operators
     * (SAA-10, M7), not a super-admin form"* — true about the plan, and not
     * true about the code: onboarding did not exist, so **nothing** created
     * operators. The platform owner had a merchant list they could not add a
     * merchant to, and the only way to take on a customer was to edit a seeder.
     *
     * `CreateTenant` is that form now, and it goes through
     * {@see OnboardOperator} rather than
     * writing a row: an operator is a tenant, a brand profile and an invited
     * owner, and a bare insert would produce a business nobody can sign in to.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * SEC-16's three conditions are met by the screen, not by this method.
     *
     * A policy can only say who; the confirmation, the reason and the audit row
     * are `EditTenant`'s, because they are properties of the *act* rather than
     * of the actor. Asserted in `AdminTenantEditTest` so a future edit page
     * cannot quietly drop them and still pass this gate.
     */
    public function update(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return false;
    }

    public function restore(User $user, Tenant $tenant): bool
    {
        return false;
    }

    /**
     * Never, from here.
     *
     * `tenant_id` cascades on delete across the whole schema (data-model §1.2),
     * so a force delete from a list screen would erase every booking, invoice
     * and ναυλοσύμφωνο an operator has. That belongs behind the GDPR erasure
     * tooling in M6, with its own confirmation and its own record.
     */
    public function forceDelete(User $user, Tenant $tenant): bool
    {
        return false;
    }
}
