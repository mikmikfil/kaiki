<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Pages\Settings;
use App\Models\ImportJob;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| SAA-13 and TEN-8: who may import
|--------------------------------------------------------------------------
|
| Owner only. An import writes trips, prices, sailings and bookings in one
| confirmed step, and reads a file of every past customer's name and email.
|
*/

it('opens the imports screen for an owner', function (): void {
    actingAs(OperatorUser::withRole(Role::Owner))->get('/app/imports?lang=el')
        ->assertSuccessful()
        ->assertSee(__('imports.title', [], 'el'));
})->group('fast');

it('refuses the imports screen to a manager and to crew', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/imports')->assertForbidden();
})->with([[Role::Manager], [Role::Crew]])->group('fast');

it('shows an owner their own import, and nobody else\'s', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    $mine = Tenancy::forTenant($tenant, static fn (): ImportJob => ImportJob::factory()->create());
    $theirs = Tenancy::forTenant(Tenant::factory()->create(), static fn (): ImportJob => ImportJob::factory()->create());

    actingAs($owner)->get('/app/imports/' . $mine->uuid)->assertSuccessful();
    actingAs($owner)->get('/app/imports/' . $theirs->uuid)->assertNotFound();
})->group('fast');

it('puts the import card in an owner\'s settings, and not a manager\'s', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $manager = OperatorUser::withRole(Role::Manager);

    $cards = static function ($user): array {
        return Tenancy::forTenant(Tenant::query()->findOrFail($user->tenant_id), static function () use ($user): array {
            actingAs($user);

            return collect((new Settings)->sections())->pluck('cards')->flatten(1)->pluck('key')->all();
        });
    };

    expect($cards($owner))->toContain('import')
        ->and($cards($manager))->not->toContain('import');
})->group('fast');
