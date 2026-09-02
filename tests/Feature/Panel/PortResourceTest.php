<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Resources\PortResource\Pages\CreatePort;
use App\Filament\App\Resources\PortResource\Pages\EditPort;
use App\Filament\App\Resources\PortResource\Pages\ListPorts;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec CAT-3, SEC-3, TEN-8, I18N-1.
 *
 * The operator's list of places. Crew never reach it: `ManageCatalogue` is
 * owner and manager only, and Filament *allows* an action when no policy is
 * registered — which is why the policy is the thing being asserted here rather
 * than the absence of a link.
 */

function portTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/**
 * A mounted page with the tenant resolved, as the panel middleware would.
 *
 * A Livewire component test does not pass through the panel middleware, so
 * `ResolveTenant` never runs and every query on a tenant-owned model throws.
 * The HTTP tests above prove the middleware does this in production.
 *
 * @param  array<string, mixed>  $params
 */
function portPageAs(User $user, string $page, array $params = []): Testable
{
    tenancy()->initialize(portTenantOf($user));

    return Livewire::actingAs($user)->test($page, $params);
}

it('lets an owner and a manager reach the ports page', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/ports')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the ports page', function (): void {
    // TEN-8: crew are read-only within a departure window, and a fleet's
    // marinas are not part of standing on the quay with a passenger list.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/ports')->assertForbidden();
})->group('fast');

it('lists only the signed-in operator ports', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $other = Tenant::factory()->create();

    $mine = Tenancy::forTenant(
        portTenantOf($owner),
        fn (): Port => Port::factory()->named(['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'])->create(),
    );

    $theirs = Tenancy::forTenant(
        $other,
        fn (): Port => Port::factory()->named(['el' => 'Λιμάνι Κέρκυρας', 'en' => 'Corfu Port'])->create(),
    );

    portPageAs($owner, ListPorts::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
})->group('fast');

it('creates a port with both locales through the form', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    portPageAs($owner, CreatePort::class)
        ->fillForm([
            'name' => ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'],
            'instructions' => ['el' => 'Στο μπλε περίπτερο', 'en' => 'At the blue kiosk'],
            'address' => 'Ακτή Θεμιστοκλέους, Πειραιάς',
            'lat' => '37.9339000',
            'lng' => '23.6469000',
            'is_active' => true,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $port = Tenancy::forTenant(portTenantOf($owner), fn (): ?Port => Port::query()->first());

    expect($port)->not->toBeNull()
        ->and($port?->getTranslation('name', 'el'))->toBe('Μαρίνα Ζέας')
        ->and($port?->getTranslation('name', 'en'))->toBe('Zea Marina')
        // The observer ran inside the same save, so the row is searchable the
        // moment it exists rather than after a second write.
        ->and($port?->search_index)->toContain('zea marina');
})->group('fast');

it('refuses a port saved with only one locale, on the field that is empty', function (): void {
    // AC 7. The operator gets a sentence beside the English box, not a 500 page
    // from the observer — and specifically beside the *English* box, which is
    // the one they have to fix.
    $owner = OperatorUser::withRole(Role::Owner);

    portPageAs($owner, CreatePort::class)
        ->fillForm([
            'name' => ['el' => 'Μαρίνα Ζέας', 'en' => ''],
            'address' => 'Ακτή Θεμιστοκλέους',
        ])
        ->call('create')
        ->assertHasFormErrors(['name.en']);

    expect(Tenancy::forTenant(portTenantOf($owner), fn (): int => Port::query()->count()))->toBe(0);
})->group('fast', 'i18n');

it('lets a manager edit and soft-delete a port', function (): void {
    $manager = OperatorUser::withRole(Role::Manager);

    $port = Tenancy::forTenant(portTenantOf($manager), fn (): Port => Port::factory()->create());

    // Addressed by uuid, not id: `HasUuid` makes the uuid the route key, so
    // the panel never puts an auto-increment id in a URL (data-model §1.1).
    portPageAs($manager, EditPort::class, ['record' => $port->uuid])
        ->fillForm(['maps_url' => 'https://maps.app.goo.gl/kaiki'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($port->fresh()?->maps_url)->toBe('https://maps.app.goo.gl/kaiki');

    portPageAs($manager, ListPorts::class)->callTableAction('delete', $port);

    expect(Tenancy::forTenant(portTenantOf($manager), fn (): int => Port::query()->count()))->toBe(0)
        ->and(Tenancy::forTenant(portTenantOf($manager), fn (): int => Port::withTrashed()->count()))->toBe(1);
})->group('fast');

it('searches and sorts ports without touching a JSON path', function (): void {
    // The queries themselves are proven in PortTest; this proves the *table*
    // is wired to them. `NoJsonPathQueryTest` catches the static shape, but a
    // column that quietly stopped being sortable would still pass that.
    $owner = OperatorUser::withRole(Role::Owner);

    [$zea, $corfu] = Tenancy::forTenant(portTenantOf($owner), fn (): array => [
        Port::factory()->named(['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'])->create(),
        Port::factory()->named(['el' => 'Λιμάνι Κέρκυρας', 'en' => 'Corfu Port'])->create(),
    ]);

    portPageAs($owner, ListPorts::class)
        // Unaccented, which is how an operator types it about half the time.
        ->searchTable('ζεασ')
        ->assertCanSeeTableRecords([$zea])
        ->assertCanNotSeeTableRecords([$corfu]);

    portPageAs($owner, ListPorts::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords([$corfu, $zea], inOrder: true);
})->group('fast');

it('renders port labels from lang files, in the operator language', function (string $locale, string $expected): void {
    // Over **HTTP**, not as a Livewire component test. `SetLocale` runs in the
    // panel middleware stack, which `Livewire::test()` bypasses entirely — a
    // component test renders in `config('app.locale')` whatever the operator
    // chose, so it would pass in English for both cases and prove nothing.
    //
    // A row has to exist too: Filament renders the empty state instead of the
    // header row when the table has none, so an assertion on a column label
    // against an empty table is green without ever seeing the label.
    $owner = OperatorUser::withRole(Role::Owner);
    $owner->update(['locale' => $locale]);

    Tenancy::forTenant(portTenantOf($owner), fn (): Port => Port::factory()->create());

    actingAs($owner)->get('/app/ports')
        ->assertSuccessful()
        ->assertSee($expected)
        // I18N-1 relies on a missing string *looking* missing. A raw dotted key
        // on screen is what an unresolved lang line renders as.
        ->assertDontSee('catalog.port.');
})->with([
    ['el', 'Όνομα'],
    ['en', 'Name'],
])->group('fast', 'i18n');
