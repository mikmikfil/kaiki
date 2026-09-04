<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Resources\DepartureResource;
use App\Filament\App\Resources\DepartureResource\Pages\CreateDeparture;
use App\Filament\App\Resources\DepartureResource\Pages\EditDeparture;
use App\Filament\App\Resources\DepartureResource\Pages\ListDepartures;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec AVL-11, AVL-52, TEN-8, SEC-3, I18N-1, CNV-5.
 *
 * The first resource where the **row set** depends on the role. Crew are
 * read-only under TEN-8, and someone standing on the quay needs tomorrow's list
 * rather than the season's — so the query is narrowed for them. That is a scope
 * on top of the policy, not instead of it: the policy already refuses every
 * write, and this answers the different question of what they may read.
 */

function departureTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/** @param array<string, mixed> $params */
function departurePageAs(User $user, string $page, array $params = []): Testable
{
    tenancy()->initialize(departureTenantOf($user));

    return Livewire::actingAs($user)->test($page, $params);
}

function seatProductFor(Tenant $tenant): Product
{
    return Tenancy::forTenant($tenant, function (): Product {
        $vessel = Vessel::factory()->create(['capacity_max' => 30]);

        return Product::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'max_pax' => 12,
            'duration_minutes' => 240,
        ]);
    });
}

it('lets an owner and a manager reach the departures page', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/departures')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('lets crew reach it too, unlike every other catalogue screen', function (): void {
    // `DeparturePolicy` is the first to split read from write: crew hold
    // `ViewDepartures` because the passenger list is their job.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/departures')->assertSuccessful();
})->group('fast');

it('shows crew only the next few days', function (): void {
    Queue::fake();

    $crew = OperatorUser::withRole(Role::Crew);
    $tenant = departureTenantOf($crew);
    $product = seatProductFor($tenant);

    $today = Carbon::now('Europe/Athens');

    [$soon, $later] = Tenancy::forTenant($tenant, fn (): array => [
        Departure::factory()->at($today->toDateString(), '09:00')->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $product->vessel_id,
        ]),
        Departure::factory()->at($today->copy()->addDays(30)->toDateString(), '09:00')->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $product->vessel_id,
        ]),
    ]);

    departurePageAs($crew, ListDepartures::class)
        ->assertCanSeeTableRecords([$soon])
        // The season's list is not what someone on the quay needs, and it is
        // not what TEN-8 gives them.
        ->assertCanNotSeeTableRecords([$later]);
})->group('fast');

it('shows an owner the whole calendar', function (): void {
    Queue::fake();

    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = departureTenantOf($owner);
    $product = seatProductFor($tenant);

    $later = Tenancy::forTenant($tenant, fn (): Departure => Departure::factory()
        ->at(Carbon::now('Europe/Athens')->addDays(30)->toDateString(), '09:00')
        ->create(['product_id' => $product->getKey(), 'vessel_id' => $product->vessel_id]));

    departurePageAs($owner, ListDepartures::class)->assertCanSeeTableRecords([$later]);
})->group('fast');

it('creates a manual departure through the form', function (): void {
    Queue::fake();

    $owner = OperatorUser::withRole(Role::Owner);
    $product = seatProductFor(departureTenantOf($owner));

    departurePageAs($owner, CreateDeparture::class)
        ->fillForm([
            'product_id' => $product->getKey(),
            'local_date' => '2026-07-04',
            'local_time' => '09:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(departureTenantOf($owner), function (): void {
        $departure = Departure::query()->firstOrFail();

        expect($departure->schedule_rule_id)->toBeNull()
            ->and($departure->capacity)->toBe(12)
            // The assertion that matters, and the one whose absence hid a real
            // bug: the panel registers a tenant display timezone (CNV-2), so a
            // date or time picker converts its state to UTC on the way out.
            // These two columns are wall-clock values rather than instants, and
            // letting that conversion happen turned an operator's 09:00 on the
            // 4th into 06:00 on the 3rd before the Action ever saw it.
            ->and($departure->local_date->toDateString())->toBe('2026-07-04')
            ->and($departure->local_time)->toBe('09:00:00')
            ->and($departure->starts_at_utc->toDateTimeString())->toBe('2026-07-04 06:00:00');
    });
})->group('fast');

it('shows the conflict warning on the field rather than as a loose banner', function (): void {
    // The Action reports on `local_time`, which is what the API will see.
    // Without the re-keying the operator gets a message attached to nothing and
    // no idea which control to change.
    Queue::fake();

    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = departureTenantOf($owner);
    $first = seatProductFor($tenant);

    $second = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create([
        'vessel_id' => $first->vessel_id,
        'max_pax' => 12,
        'duration_minutes' => 240,
    ]));

    Tenancy::forTenant($tenant, function () use ($first): void {
        Departure::factory()->at('2026-07-04', '09:00')->create([
            'product_id' => $first->getKey(),
            'vessel_id' => $first->vessel_id,
        ]);
    });

    departurePageAs($owner, CreateDeparture::class)
        ->fillForm([
            'product_id' => $second->getKey(),
            'local_date' => '2026-07-04',
            'local_time' => '09:00',
            'confirm_conflict' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['local_time']);
})->group('fast');

it('creates it anyway once the operator confirms', function (): void {
    // AVL-11 warns; it does not block. Two products on one boat at one hour is
    // a strategy, and the bookings decide.
    Queue::fake();

    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = departureTenantOf($owner);
    $first = seatProductFor($tenant);

    $second = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create([
        'vessel_id' => $first->vessel_id,
        'max_pax' => 12,
        'duration_minutes' => 240,
    ]));

    Tenancy::forTenant($tenant, function () use ($first): void {
        Departure::factory()->at('2026-07-04', '09:00')->create([
            'product_id' => $first->getKey(),
            'vessel_id' => $first->vessel_id,
        ]);
    });

    departurePageAs($owner, CreateDeparture::class)
        ->fillForm([
            'product_id' => $second->getKey(),
            'local_date' => '2026-07-04',
            'local_time' => '09:00',
            'confirm_conflict' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant($tenant, function (): void {
        expect(Departure::query()->count())->toBe(2);
    });
})->group('fast');

it('refuses through the form to lower capacity below what is sold', function (): void {
    Queue::fake();

    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = departureTenantOf($owner);
    $product = seatProductFor($tenant);

    $departure = Tenancy::forTenant($tenant, fn (): Departure => Departure::factory()
        ->at('2026-07-04', '09:00')
        ->withSeats(sold: 10)
        ->create(['product_id' => $product->getKey(), 'vessel_id' => $product->vessel_id, 'capacity' => 12]));

    departurePageAs($owner, EditDeparture::class, ['record' => $departure->uuid])
        ->fillForm(['capacity' => 8])
        ->call('save')
        ->assertHasFormErrors(['capacity']);
})->group('fast');

it('hides the create button from crew', function (): void {
    // Filament hides an action a policy denies, and `DeparturePolicy` splits
    // read from write — so this is stated rather than assumed.
    Queue::fake();

    $crew = OperatorUser::withRole(Role::Crew);

    tenancy()->initialize(departureTenantOf($crew));
    actingAs($crew);

    expect(DepartureResource::canCreate())->toBeFalse();
})->group('fast');

it('renders departure labels from lang files, in the operator language', function (string $locale, string $expected): void {
    Queue::fake();

    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = departureTenantOf($owner);
    $product = seatProductFor($tenant);

    Tenancy::forTenant($tenant, function () use ($product): void {
        Departure::factory()->at(Carbon::now('Europe/Athens')->toDateString(), '09:00')->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $product->vessel_id,
        ]);
    });

    $owner->update(['locale' => $locale]);

    actingAs($owner)->get('/app/departures')
        ->assertSuccessful()
        ->assertSee($expected)
        ->assertDontSee('availability.departure.');
})->with([
    ['el', 'Πουλημένες'],
    ['en', 'Sold'],
])->group('fast', 'i18n');
