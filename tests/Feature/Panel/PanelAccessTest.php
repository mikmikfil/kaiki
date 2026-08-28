<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Spec SCP-1 / SCP-13: two panels, strictly separate populations.
 *
 * An operator reaching `/admin` would see every operator's data. A super-admin
 * landing in `/app` has no tenant to scope to, so every query would either
 * throw or — far worse, if the scope were ever relaxed — return everything.
 * Both are refused outright rather than rendered partially, because a partial
 * render is how someone learns what exists behind a wall.
 */

it('shows the operator login page at /app', function (): void {
    get('/app/login')->assertOk();
})->group('fast');

it('shows a separate super-admin login at /admin', function (): void {
    get('/admin/login')->assertOk();
})->group('fast');

it('redirects an unauthenticated visitor away from the panel itself', function (): void {
    get('/app')->assertRedirect();
    get('/admin')->assertRedirect();
})->group('fast');

it('lets an operator into /app with their tenant resolved', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    actingAs($user)->get('/app')->assertSuccessful();
})->group('fast');

it('refuses an operator at /admin', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    $response = actingAs($user)->get('/admin');

    // Never 200: a partial render would confirm the panel exists and hint at
    // its shape.
    expect($response->status())->not->toBe(200)
        ->and($response->status())->toBeIn([403, 404, 302]);
})->group('fast');

it('lets a super-admin into /admin', function (): void {
    $admin = User::factory()->superAdmin()->create();

    actingAs($admin)->get('/admin')->assertSuccessful();
})->group('fast');

it('refuses a super-admin at /app, because impersonation is the only way in', function (): void {
    // TEN-7 makes reaching an operator an audited action rather than an
    // implicit privilege. Until that lands in M7, there is no way in at all.
    $admin = User::factory()->superAdmin()->create();

    $response = actingAs($admin)->get('/app');

    expect($response->status())->not->toBe(200)
        ->and($response->status())->toBeIn([403, 404, 302]);
})->group('fast');

it('refuses a user with no tenant and no super-admin flag', function (): void {
    // An orphaned account belongs to neither panel. Fail closed.
    $stranded = User::factory()->create(['tenant_id' => null, 'is_super_admin' => false]);

    expect(actingAs($stranded)->get('/app')->status())->not->toBe(200);
    expect(actingAs($stranded)->get('/admin')->status())->not->toBe(200);
})->group('fast');
