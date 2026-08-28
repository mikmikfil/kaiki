<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Capability;

/**
 * Staff management (TEN-8: owner only).
 *
 * A manager runs the business but cannot change who has access to it — adding
 * a colleague is how someone grants themselves more than they had.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        // Seeing who your colleagues are is not privileged; anyone who can open
        // the panel works here.
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, User $subject): bool
    {
        return ! $user->isSuperAdmin() && $user->tenant_id === $subject->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->hasCapability(Capability::ManageStaff);
    }

    public function update(User $user, User $subject): bool
    {
        // Everyone may edit their own profile — name, locale, password — which
        // is not the same as managing staff.
        if ($user->getKey() === $subject->getKey()) {
            return true;
        }

        return $user->hasCapability(Capability::ManageStaff)
            && $user->tenant_id === $subject->tenant_id;
    }

    public function delete(User $user, User $subject): bool
    {
        // Deleting yourself is a support ticket, not a button. The last-owner
        // guard in RoleAssignment covers the related case of removing the only
        // owner's role.
        if ($user->getKey() === $subject->getKey()) {
            return false;
        }

        return $user->hasCapability(Capability::ManageStaff)
            && $user->tenant_id === $subject->tenant_id;
    }
}
