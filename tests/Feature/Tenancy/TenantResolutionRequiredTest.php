<?php

declare(strict_types=1);

use App\Exceptions\TenantContextMissingException;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;

it('throws rather than returning cross-tenant rows when no tenant is resolved', function (): void {
    // Spec TEN-4: no default tenant, no fallback. Silently returning every
    // tenant's rows is the worst available failure mode for this product, so
    // the absence of context has to be louder than a wrong answer.
    RoleAssignment::query()->count();
})->throws(TenantContextMissingException::class)->group('fast');

it('names the model in the exception so the fix is obvious', function (): void {
    expect(fn () => RoleAssignment::query()->first())
        ->toThrow(TenantContextMissingException::class, RoleAssignment::class);
})->group('fast');

it('refuses to create a tenant-owned row with no tenant resolved', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    RoleAssignment::query()->create(['user_id' => $user->getKey(), 'role' => 'crew']);
})->throws(TenantContextMissingException::class)->group('fast');

it('allows deliberate cross-tenant access through withoutTenancy', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $userA = User::factory()->forTenant($a)->create();
    $userB = User::factory()->forTenant($b)->create();

    Tenancy::forTenant($a, fn () => RoleAssignment::query()->create(['user_id' => $userA->getKey(), 'role' => 'owner']));
    Tenancy::forTenant($b, fn () => RoleAssignment::query()->create(['user_id' => $userB->getKey(), 'role' => 'owner']));

    $all = Tenancy::withoutTenancy(fn (): int => RoleAssignment::query()->count());

    expect($all)->toBe(2);
})->group('fast');

it('restores the scope after withoutTenancy, including when the callback throws', function (): void {
    try {
        Tenancy::withoutTenancy(function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    // If the suspension leaked, this would return 0 instead of throwing — and
    // every later query in the process would silently run unscoped.
    expect(fn () => RoleAssignment::query()->count())
        ->toThrow(TenantContextMissingException::class);
})->group('fast');

it('leaves platform-owned models queryable with no tenant', function (): void {
    Tenant::factory()->count(2)->create();

    // `tenants` and `users` are named in config/tenancy.php platform_owned_models.
    expect(Tenant::query()->count())->toBe(2)
        ->and(User::query()->count())->toBe(0);
})->group('fast');
