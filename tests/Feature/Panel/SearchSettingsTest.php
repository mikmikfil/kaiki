<?php

declare(strict_types=1);

use App\Domain\Catalog\Support\SearchFilters;
use App\Enums\Role;
use App\Filament\App\Pages\SearchSettings;
use App\Models\Tenant;
use App\Support\Tenancy;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * #105, SEC-3 and TEN-8: who may decide what the search page offers.
 *
 * The screen is gated on the brand profile rather than on a policy of its own,
 * because what an operator's search page offers is a front-of-house
 * presentation choice — the same job as the logo and the colours, which
 * `ManageBranding` already governs. Crew reach none of the three.
 *
 * The assertion that matters is the **round trip**: a toggle switched off has to
 * reach `tenants.settings` in a shape `SearchFilters` reads back, or the screen
 * is decorative in exactly the way the issue's note warns about.
 */

it('lets an owner and a manager reach the search settings', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/search-settings')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the search settings', function (): void {
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/search-settings')->assertForbidden();
})->group('fast');

it('stores a switched-off filter where the search reads it', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(SearchSettings::class)
        ->fillForm(['filters' => [
            ...SearchFilters::defaults(),
            SearchFilters::PORT => false,
            SearchFilters::PRICE => true,
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = SearchFilters::for($tenant->refresh());

    expect($stored[SearchFilters::PORT])->toBeFalse()
        ->and($stored[SearchFilters::PRICE])->toBeTrue()
        // The two fixed ones snap back on however the form was submitted: a
        // search page with no date and no party size is a catalogue listing.
        ->and($stored[SearchFilters::DATE])->toBeTrue()
        ->and($stored[SearchFilters::PARTY])->toBeTrue();
})->group('fast');

it('leaves the rest of the tenant settings alone', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    // Something else already living in the blob — this column is shared, and a
    // screen that wrote the whole of it would quietly delete whatever the next
    // feature puts there.
    $tenant->forceFill(['settings' => ['unrelated' => ['kept' => true]]])->save();

    tenancy()->initialize($tenant->refresh());

    Livewire::actingAs($owner)
        ->test(SearchSettings::class)
        ->fillForm(['filters' => [...SearchFilters::defaults(), SearchFilters::VESSEL => true]])
        ->call('save');

    $settings = (array) $tenant->refresh()->settings;

    expect($settings['unrelated']['kept'] ?? null)->toBeTrue()
        ->and(SearchFilters::for($tenant)[SearchFilters::VESSEL])->toBeTrue();
})->group('fast');

it('is the same source of truth the guest surfaces read', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    Tenancy::forTenant($tenant, function () use ($tenant): void {
        // `SearchFilters::for()` with no argument reads the resolved tenant,
        // which is how the API request and the hosted page both ask. If the
        // panel wrote somewhere else, this is where it would show.
        expect(SearchFilters::for())->toBe(SearchFilters::for($tenant));
    });
})->group('fast');
