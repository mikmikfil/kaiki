<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\VesselAmenity;
use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Filament\App\Resources\VesselResource\Pages\CreateVessel;
use App\Filament\App\Resources\VesselResource\Pages\EditVessel;
use App\Filament\App\Resources\VesselResource\Pages\ListVessels;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\Catalog\FakeCapacityClaims;
use Tests\Support\OperatorUser;

/*
 * Spec CAT-1, CAT-2, TEN-6, TEN-8, SEC-3, I18N-1.
 *
 * The fleet screen. Two things here are worth more than the CRUD: the capacity
 * guard, which is a legal limit rather than a preference, and the fact that
 * every sort and search goes through a companion column rather than the raw
 * Greek — because the two engines disagree about Greek and production is the
 * one that would be wrong.
 */

function vesselTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/**
 * A mounted page with the tenant resolved, as the panel middleware would.
 *
 * @param  array<string, mixed>  $params
 */
function vesselPageAs(User $user, string $page, array $params = []): Testable
{
    tenancy()->initialize(vesselTenantOf($user));

    return Livewire::actingAs($user)->test($page, $params);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vesselFormData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Οδυσσέας',
        'type' => VesselType::TraditionalKaiki->value,
        'status' => VesselStatus::Active->value,
        'capacity_max' => 42,
        'crew_count' => 3,
        'description' => ['el' => 'Παραδοσιακό καΐκι', 'en' => 'A traditional kaiki'],
    ], $overrides);
}

it('lets an owner and a manager reach the vessels page', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/vessels')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the vessels page', function (): void {
    // TEN-8. Filament *allows* an action when no policy is registered, so this
    // asserts the policy exists rather than that a link is hidden.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/vessels')->assertForbidden();
})->group('fast');

it('lists only the signed-in operator vessels', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $mine = Tenancy::forTenant(vesselTenantOf($owner), fn (): Vessel => Vessel::factory()->named('Οδυσσέας')->create());
    $theirs = Tenancy::forTenant(Tenant::factory()->create(), fn (): Vessel => Vessel::factory()->named('Ποσειδών')->create());

    vesselPageAs($owner, ListVessels::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
})->group('fast');

it('creates a vessel through the form, in metres', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $port = Tenancy::forTenant(vesselTenantOf($owner), fn (): Port => Port::factory()->create());

    vesselPageAs($owner, CreateVessel::class)
        ->fillForm(vesselFormData([
            'home_port_id' => $port->getKey(),
            'length_m' => 13.5,
            'specs' => ['year_built' => 1978, 'amenities' => [VesselAmenity::Fridge->value]],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $vessel = Tenancy::forTenant(vesselTenantOf($owner), fn (): ?Vessel => Vessel::query()->first());

    expect($vessel?->name)->toBe('Οδυσσέας')
        // Metres in, centimetres stored, exactly — 13.5 m is 1350 cm and not
        // 1349, which is what a plain `(int)` cast of a binary float gives.
        ->and($vessel?->length_cm)->toBe(1350)
        ->and($vessel?->home_port_id)->toBe($port->getKey())
        // Null, not the tenant's 60: a new boat inherits (AVL-7), and
        // pre-filling the default would make the account setting decorative.
        ->and($vessel?->turnaround_buffer_minutes)->toBeNull()
        ->and($vessel?->specs['year_built'] ?? null)->toBe(1978)
        ->and($vessel?->search_index)->toContain('οδυσσεασ');
})->group('fast');

it('round-trips the length through the edit form without losing a centimetre', function (): void {
    // Written twice by two code paths — fill and save — so a boat that lost a
    // centimetre on every edit would eventually lose a metre, quietly.
    $owner = OperatorUser::withRole(Role::Owner);

    $vessel = Tenancy::forTenant(vesselTenantOf($owner), fn (): Vessel => Vessel::factory()->create(['length_cm' => 1350]));

    vesselPageAs($owner, EditVessel::class, ['record' => $vessel->uuid])
        ->assertFormSet(['length_m' => 13.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($vessel->fresh()?->length_cm)->toBe(1350);
})->group('fast');

it('preserves specs keys the form does not render', function (): void {
    // §3.9: "unknown keys are preserved but not rendered". A form that wrote
    // its own state over the column would delete an importer's extra fields on
    // the first edit, and nothing would report it.
    $owner = OperatorUser::withRole(Role::Owner);

    $vessel = Tenancy::forTenant(vesselTenantOf($owner), fn (): Vessel => Vessel::factory()->create([
        'specs' => ['year_built' => 1978, 'hull_material' => 'oak'],
    ]));

    vesselPageAs($owner, EditVessel::class, ['record' => $vessel->uuid])
        ->fillForm(['specs' => ['year_built' => 1980]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($vessel->fresh()?->specs)
        ->toHaveKey('hull_material', 'oak')
        ->toHaveKey('year_built', 1980);
})->group('fast');

it('refuses a duplicate vessel name within the same operator', function (): void {
    // TEN-6, as a form error rather than a constraint-violation page.
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(vesselTenantOf($owner), fn (): Vessel => Vessel::factory()->named('Οδυσσέας')->create());

    vesselPageAs($owner, CreateVessel::class)
        ->fillForm(vesselFormData(['name' => 'Οδυσσέας']))
        ->call('create')
        ->assertHasFormErrors(['name']);
})->group('fast');

it('lets two operators each have a vessel with the same name', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(Tenant::factory()->create(), fn (): Vessel => Vessel::factory()->named('Οδυσσέας')->create());

    vesselPageAs($owner, CreateVessel::class)
        ->fillForm(vesselFormData(['name' => 'Οδυσσέας']))
        ->call('create')
        ->assertHasNoFormErrors();
})->group('fast');

it('shows the capacity guard as a field error rather than an exception', function (): void {
    // The humane half of the guard. The observer throwing is correct for an
    // import; for a person mid-edit it is a 500 page that loses their work.
    FakeCapacityClaims::register([
        ['kind' => 'departure', 'label' => 'Σάββατο 14 Ιουν, 10:00', 'pax' => 30],
    ]);

    $owner = OperatorUser::withRole(Role::Owner);
    $owner->update(['locale' => 'el']);

    $vessel = Tenancy::forTenant(vesselTenantOf($owner), fn (): Vessel => Vessel::factory()->capacity(40)->create());

    vesselPageAs($owner, EditVessel::class, ['record' => $vessel->uuid])
        ->fillForm(['capacity_max' => 20])
        ->call('save')
        ->assertHasFormErrors(['capacity_max']);

    expect($vessel->fresh()?->capacity_max)->toBe(40);
})->group('fast');

it('does not apply the capacity guard when creating a vessel', function (): void {
    // A boat that does not exist has promised nothing, and there is no previous
    // capacity to compare against.
    FakeCapacityClaims::register([
        ['kind' => 'departure', 'label' => 'Sat 14 Jun, 10:00', 'pax' => 999],
    ]);

    $owner = OperatorUser::withRole(Role::Owner);

    vesselPageAs($owner, CreateVessel::class)
        ->fillForm(vesselFormData(['capacity_max' => 8]))
        ->call('create')
        ->assertHasNoFormErrors();
})->group('fast');

it('lets a manager soft-delete and restore a vessel', function (): void {
    $manager = OperatorUser::withRole(Role::Manager);

    $vessel = Tenancy::forTenant(vesselTenantOf($manager), fn (): Vessel => Vessel::factory()->create());

    vesselPageAs($manager, ListVessels::class)->callTableAction('delete', $vessel);

    expect(Tenancy::forTenant(vesselTenantOf($manager), fn (): int => Vessel::query()->count()))->toBe(0);

    // Re-read with the soft-delete scope lifted. `$vessel` is the pre-delete
    // instance and still reports itself as live, and `RestoreAction` hides
    // itself for a record that is not trashed — so passing the stale model
    // fails on visibility rather than on anything an operator would hit.
    $trashed = Tenancy::forTenant(
        vesselTenantOf($manager),
        fn (): Vessel => Vessel::withTrashed()->findOrFail($vessel->getKey()),
    );

    vesselPageAs($manager, ListVessels::class)
        // `false` is only-trashed on Filament's TrashedFilter; `true` is
        // with-trashed, which would also list the live rows.
        ->filterTable('trashed', false)
        ->callTableAction('restore', $trashed);

    expect(Tenancy::forTenant(vesselTenantOf($manager), fn (): int => Vessel::query()->count()))->toBe(1);
})->group('fast');

it('searches and sorts the fleet through the folded companion columns', function (): void {
    // The reason `name` has a companion column despite not being translatable.
    // A raw `like` here would match in production and miss locally.
    $owner = OperatorUser::withRole(Role::Owner);

    [$odysseas, $poseidon] = Tenancy::forTenant(vesselTenantOf($owner), fn (): array => [
        Vessel::factory()->named('Οδυσσεύς')->create(),
        Vessel::factory()->named('Άλφα')->create(),
    ]);

    vesselPageAs($owner, ListVessels::class)
        ->searchTable('οδυσσευσ')
        ->assertCanSeeTableRecords([$odysseas])
        ->assertCanNotSeeTableRecords([$poseidon]);

    vesselPageAs($owner, ListVessels::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords([$poseidon, $odysseas], inOrder: true);
})->group('fast');

it('renders vessel labels from lang files, in the operator language', function (string $locale, string $expected): void {
    // Over HTTP, because `SetLocale` lives in the panel middleware and a
    // Livewire component test never reaches it. A row must exist too, or
    // Filament renders the empty state instead of the column headers.
    $owner = OperatorUser::withRole(Role::Owner);
    $owner->update(['locale' => $locale]);

    Tenancy::forTenant(vesselTenantOf($owner), fn (): Vessel => Vessel::factory()->create());

    actingAs($owner)->get('/app/vessels')
        ->assertSuccessful()
        ->assertSee($expected)
        ->assertDontSee('catalog.vessel.')
        // Enum labels resolve too: `HasTranslatedLabel` returns the dotted key
        // rather than the raw value precisely so this can fail.
        ->assertDontSee('enums.vessel_type.');
})->with([
    ['el', 'Επιβάτες'],
    ['en', 'Passengers'],
])->group('fast', 'i18n');
