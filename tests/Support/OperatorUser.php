<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;

/**
 * Builds an operator with a role, for panel tests.
 *
 * A role assignment is tenant-owned, so creating one needs a resolved tenant —
 * which is the sort of detail every panel test would otherwise repeat, and get
 * subtly wrong once.
 */
final class OperatorUser
{
    public static function withRole(Role $role, ?Tenant $tenant = null): User
    {
        $tenant ??= Tenant::factory()->create();

        $user = User::factory()->forTenant($tenant)->create();

        Tenancy::forTenant($tenant, static fn (): RoleAssignment => RoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role' => $role,
        ]));

        return $user->refresh();
    }
}
