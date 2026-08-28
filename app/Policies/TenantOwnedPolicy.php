<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;
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

    public function create(User $user): bool
    {
        return $user->hasCapability($this->manageCapability());
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasCapability($this->manageCapability()) && $this->sameTenant($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasCapability($this->manageCapability()) && $this->sameTenant($user, $model);
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->hasCapability($this->manageCapability()) && $this->sameTenant($user, $model);
    }

    /**
     * Permanent deletion is the owner's call alone.
     *
     * Soft delete is recoverable and belongs to whoever manages the resource;
     * this is not, so it sits with the person who answers for the account.
     */
    public function forceDelete(User $user, Model $model): bool
    {
        return $user->hasCapability(Capability::DeleteTenant) && $this->sameTenant($user, $model);
    }

    protected function sameTenant(User $user, Model $model): bool
    {
        $tenantId = $model->getAttribute('tenant_id');

        return $tenantId !== null && (int) $tenantId === $user->tenant_id;
    }
}
