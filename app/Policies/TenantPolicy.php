<?php

declare(strict_types=1);

namespace App\Policies;

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
 * **Read-only, and that is load-bearing rather than timidity.** The moment
 * `/admin` can change an operator's record, SEC-16's "confirmed and
 * audit-logged with actor, timestamp and reason" applies — and the audit log is
 * exactly what #42's ADR-0025 has not decided yet. Refusing every write here is
 * what lets the merchant list ship before that decision, and the refusals are
 * asserted rather than left implicit: Filament allows an action when nothing
 * forbids it, so "we did not build an edit page" is not the same as "editing is
 * refused". The next person to add a page would otherwise get one for free.
 *
 * Suspending, editing and deleting an operator all arrive in M7 with
 * impersonation (TEN-7) and the audit trail behind them.
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

    public function create(User $user): bool
    {
        // Onboarding creates operators (SAA-10, M7), not a super-admin form.
        return false;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return false;
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
