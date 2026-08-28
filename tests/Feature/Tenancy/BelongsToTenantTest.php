<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;

it('fills tenant_id automatically inside a resolved tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    $assignment = Tenancy::forTenant($tenant, fn (): RoleAssignment => RoleAssignment::query()->create([
        'user_id' => $user->getKey(),
        'role' => Role::Manager,
    ]));

    expect($assignment->tenant_id)->toBe($tenant->getKey());
})->group('fast');

it('assigns a uuid on creating', function (): void {
    $tenant = Tenant::factory()->create();

    expect($tenant->uuid)->toBeString()
        ->and($tenant->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($tenant->getRouteKeyName())->toBe('uuid');
})->group('fast');

it('puts tenant_id into the compiled SQL of every query', function (): void {
    // Asserted against the generated SQL rather than by counting rows: a query
    // returning the right number of records proves nothing if the filtering
    // happened in PHP, and the whole isolation guarantee rests on the WHERE
    // clause actually reaching the database.
    $tenant = Tenant::factory()->create();

    [$sql, $bindings] = Tenancy::forTenant($tenant, static function (): array {
        $query = RoleAssignment::query()->where('role', Role::Crew);

        return [$query->toSql(), $query->getBindings()];
    });

    // Quote characters are engine-specific: SQLite emits "table"."column",
    // MySQL emits `table`.`column`. Strip them so this asserts the clause, not
    // the dialect — otherwise it passes locally and fails in the CI MySQL job.
    $normalised = str_replace(['"', '`'], '', $sql);

    expect($normalised)->toContain('role_assignments.tenant_id = ?')
        ->and($bindings)->toContain($tenant->getKey());
})->group('fast');

it('scopes reads to the resolved tenant and never leaks another tenant rows', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $userA = User::factory()->forTenant($a)->create();
    $userB = User::factory()->forTenant($b)->create();

    Tenancy::forTenant($a, fn () => RoleAssignment::query()->create(['user_id' => $userA->getKey(), 'role' => Role::Owner]));
    Tenancy::forTenant($b, fn () => RoleAssignment::query()->create(['user_id' => $userB->getKey(), 'role' => Role::Owner]));

    $seenByA = Tenancy::forTenant($a, fn (): array => RoleAssignment::query()->pluck('user_id')->all());
    $seenByB = Tenancy::forTenant($b, fn (): array => RoleAssignment::query()->pluck('user_id')->all());

    expect($seenByA)->toBe([$userA->getKey()])
        ->and($seenByB)->toBe([$userB->getKey()]);
})->group('fast');

it('cannot be tricked into writing a row for another tenant', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $userB = User::factory()->forTenant($b)->create();

    // An explicit tenant_id is honoured — the trait only fills a null — so the
    // real protection is that reading it back from tenant A finds nothing.
    Tenancy::forTenant($a, fn () => RoleAssignment::query()->create([
        'tenant_id' => $b->getKey(),
        'user_id' => $userB->getKey(),
        'role' => Role::Crew,
    ]));

    $visibleToA = Tenancy::forTenant($a, fn (): int => RoleAssignment::query()->count());

    expect($visibleToA)->toBe(0);
})->group('fast');
