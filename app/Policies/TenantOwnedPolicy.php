<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared base for policies on tenant-owned resources (spec SEC-3, TEN-8).
 *
 * A subclass names the capabilities that gate reading and writing its resource;
 * the matrix in {@see Capability} decides which roles hold them. Keeping the
 * matrix in one enum rather than spread across policies means the answer to
 * "what can crew do" is one file, not a search.
 *
 * Tenant *isolation* is not this class's job — the global scope handles that
 * and #8 proves it. These policies answer the different question of what a
 * signed-in operator user may do with rows already visible to them. The
 * `tenant_id` comparisons below are belt and braces for the case where a policy
 * is somehow reached with a row from outside the scope.
 *
 * ## Read-only mode is enforced here, not on the HTTP verb (#43, TEN-9)
 *
 * `EnsureTenantIsWritable` gates on the request method and is correct on the
 * API. It is **powerless in the panel**: every Filament action is a POST to
 * `/livewire/update`, and Livewire re-runs persistent middleware against a
 * synthesized request that restores the *original* page-load method — a `GET`.
 * The middleware's safe-method short-circuit fires and the tenant's state is
 * never consulted. Silent, total, and it applied to every write in `/app`.
 *
 * Blocking that POST wholesale is not the fix either: sorting a table,
 * searching, paginating, opening a modal and switching a tab are all POSTs to
 * the same endpoint, and TEN-9 asks for none of those to break.
 *
 * So the guard sits where the panel already asks a question about writing.
 * Every write in `/app` passes an authorization check — resource CRUD through
 * Filament, the branding page through `Gate::allows('update', ...)`, the API
 * key revoke through `can('update', ...)` — and `PolicyCoverageTest` requires
 * every resource model to have a policy, so every resource inherits this
 * without remembering it. A new resource cannot forget.
 *
 * Reads are untouched: `viewAny` and `view` never consult writability, and
 * guest and widget paths do not run through operator policies at all. That is
 * SAA-7's asymmetry — the pressure lands on the person who owes money, not on
 * the tourist holding a ticket.
 */
abstract class TenantOwnedPolicy
{
    abstract protected function viewCapability(): Capability;

    abstract protected function manageCapability(): Capability;

    public function viewAny(User $user): bool
    {
        return $user->hasCapability($this->viewCapability());
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasCapability($this->viewCapability()) && $this->sameTenant($user, $model);
    }

    public function create(User $user): Response|bool
    {
        return $this->refusalWhenReadOnly()
            ?? $user->hasCapability($this->manageCapability());
    }

    public function update(User $user, Model $model): Response|bool
    {
        return $this->refusalWhenReadOnly()
            ?? ($user->hasCapability($this->manageCapability()) && $this->sameTenant($user, $model));
    }

    public function delete(User $user, Model $model): Response|bool
    {
        return $this->refusalWhenReadOnly()
            ?? ($user->hasCapability($this->manageCapability()) && $this->sameTenant($user, $model));
    }

    public function restore(User $user, Model $model): Response|bool
    {
        return $this->refusalWhenReadOnly()
            ?? ($user->hasCapability($this->manageCapability()) && $this->sameTenant($user, $model));
    }

    /**
     * Permanent deletion is the owner's call alone.
     *
     * Soft delete is recoverable and belongs to whoever manages the resource;
     * this is not, so it sits with the person who answers for the account.
     */
    public function forceDelete(User $user, Model $model): Response|bool
    {
        return $this->refusalWhenReadOnly()
            ?? ($user->hasCapability(Capability::DeleteTenant) && $this->sameTenant($user, $model));
    }

    /**
     * TEN-9: a lapsed subscription refuses every write, with the reason.
     *
     * `Response::deny` rather than a bare `false`, because an operator reads the
     * two very differently: `false` renders as "you are not allowed", which for
     * an owner looking at their own catalogue is both wrong and alarming. The
     * denial carries the localised sentence that says what actually happened and
     * that their guests are unaffected (CNV-8, I18N-3).
     *
     * Null means "no opinion", and the capability check decides — so the
     * ordinary path is exactly what it was.
     */
    protected function refusalWhenReadOnly(): ?Response
    {
        $tenant = Tenancy::current();

        if ($tenant === null || $tenant->allowsWrites()) {
            return null;
        }

        return Response::deny(__('errors.tenant_read_only'));
    }

    protected function sameTenant(User $user, Model $model): bool
    {
        $tenantId = $model->getAttribute('tenant_id');

        return $tenantId !== null && (int) $tenantId === $user->tenant_id;
    }
}
