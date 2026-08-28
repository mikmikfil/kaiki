<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\Authorization\Capability;

/**
 * Granting and revoking roles (TEN-8: owner only).
 *
 * The most privilege-sensitive action in the panel: whoever can hand out roles
 * can hand themselves one. Kept with the owner for that reason alone.
 *
 * The model's own `deleting` guard separately refuses to remove the last owner
 * (data-model §2.1). Being *allowed* to try and the attempt being *safe* are
 * different questions, answered in different places on purpose — an owner is
 * authorised to revoke an owner role, and is still stopped from leaving the
 * account with none.
 */
class RoleAssignmentPolicy extends TenantOwnedPolicy
{
    protected function viewCapability(): Capability
    {
        return Capability::ManageStaff;
    }

    protected function manageCapability(): Capability
    {
        return Capability::ManageStaff;
    }
}
