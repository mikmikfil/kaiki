<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Enums\Role;
use App\Filament\App\Resources\ApiKeyResource\Pages\ListApiKeys;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

use function Pest\Laravel\withHeader;

use Tests\Support\OperatorUser;

/*
 * Spec TEN-3, SEC-5.
 *
 * The acceptance criterion is deliberately end to end: "a subsequent API
 * request with that key returns 401 — asserted end to end, not just at the
 * model". A test that only checks `revoked_at` is not null proves the button
 * wrote a timestamp, not that the key stopped working, and those are different
 * claims. This one presses the button in the panel and then tries the key.
 */

beforeEach(function (): void {
    // Standing in for the real API, which arrives in #35 — the same shape the
    // middleware tests from #6 use.
    Route::middleware('api.key')->get('/_test/read', fn () => response()->json(['ok' => true]));
});

it('stops a key working the moment it is revoked in the panel', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    $plainTextKey = Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Website widget',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    )->plainTextKey);

    // It works before.
    withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson('/_test/read')
        ->assertOk();

    tenancy()->initialize($tenant);
    $record = ApiKey::query()->sole();

    Livewire::actingAs($owner)
        ->test(ListApiKeys::class)
        ->callTableAction('revoke', $record)
        ->assertHasNoTableActionErrors();

    tenancy()->end();

    // And not after.
    withHeader('Authorization', "Bearer {$plainTextKey}")
        ->getJson('/_test/read')
        ->assertUnauthorized();
})->group('fast');

it('leaves the revoked key visible in the panel as the record of what it could reach', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    $record = Tenancy::forTenant($tenant, fn (): ApiKey => ApiKey::factory()->create(['name' => 'Leaked key']));

    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(ListApiKeys::class)
        ->callTableAction('revoke', $record);

    // Revocation is a timestamp, never a delete (data-model §2.1): deleting the
    // row would free its prefix for reissue and erase the evidence.
    expect(ApiKey::query()->count())->toBe(1);

    tenancy()->end();

    \Pest\Laravel\actingAs($owner)
        ->get('/app/api-keys')
        ->assertSuccessful()
        ->assertSee('Leaked key');
})->group('fast');
