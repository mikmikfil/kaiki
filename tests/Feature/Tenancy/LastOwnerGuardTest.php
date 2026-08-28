<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Exceptions\LastOwnerException;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;

it('refuses to remove the last owner of a tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->forTenant($tenant)->create();

    Tenancy::forTenant($tenant, function () use ($owner): void {
        $assignment = RoleAssignment::query()->create([
            'user_id' => $owner->getKey(),
            'role' => Role::Owner,
        ]);

        expect(fn () => $assignment->delete())->toThrow(LastOwnerException::class);
    });
})->group('fast');

it('allows removing an owner while another remains', function (): void {
    $tenant = Tenant::factory()->create();
    $first = User::factory()->forTenant($tenant)->create();
    $second = User::factory()->forTenant($tenant)->create();

    Tenancy::forTenant($tenant, function () use ($first, $second): void {
        $a = RoleAssignment::query()->create(['user_id' => $first->getKey(), 'role' => Role::Owner]);
        RoleAssignment::query()->create(['user_id' => $second->getKey(), 'role' => Role::Owner]);

        expect($a->delete())->toBeTrue()
            ->and(RoleAssignment::query()->where('role', Role::Owner)->count())->toBe(1);
    });
})->group('fast');

it('counts owners within the tenant only, so another tenant owner does not license the removal', function (): void {
    // The guard runs a scoped query. If the scope were missing, tenant B's
    // owner would count as "another owner remains" and tenant A could be left
    // with none.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $ownerA = User::factory()->forTenant($a)->create();
    $ownerB = User::factory()->forTenant($b)->create();

    Tenancy::forTenant($b, fn () => RoleAssignment::query()->create(['user_id' => $ownerB->getKey(), 'role' => Role::Owner]));

    Tenancy::forTenant($a, function () use ($ownerA): void {
        $assignment = RoleAssignment::query()->create(['user_id' => $ownerA->getKey(), 'role' => Role::Owner]);

        expect(fn () => $assignment->delete())->toThrow(LastOwnerException::class);
    });
})->group('fast');

it('does not guard non-owner roles', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->forTenant($tenant)->create();
    $crew = User::factory()->forTenant($tenant)->create();

    Tenancy::forTenant($tenant, function () use ($owner, $crew): void {
        RoleAssignment::query()->create(['user_id' => $owner->getKey(), 'role' => Role::Owner]);
        $crewRole = RoleAssignment::query()->create(['user_id' => $crew->getKey(), 'role' => Role::Crew]);

        expect($crewRole->delete())->toBeTrue();
    });
})->group('fast');
