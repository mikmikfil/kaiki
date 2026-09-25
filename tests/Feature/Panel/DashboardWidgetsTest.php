<?php

declare(strict_types=1);

use App\Domain\Operations\Support\FirstSteps;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\Role;
use App\Filament\App\Resources\DepartureResource;
use App\Filament\App\Widgets\DayByBoat;
use App\Filament\App\Widgets\NeedsAttention;
use App\Filament\App\Widgets\OperationsOverview;
use App\Filament\App\Widgets\TodayAtSea;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Port;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| #130: the fleet strip and the decision list, on the dashboard
|--------------------------------------------------------------------------
|
| Both widgets hide themselves on an operator's first afternoon, for the reason
| #118 already established: six zeros and an empty list is a product that looks
| broken on the day somebody decides whether to keep paying for it, and
| `FirstSteps` owns that screen instead.
|
| The strip contributes no arithmetic of its own — every bar comes from
| `CalendarDay`, the class the full calendar page uses — so what is worth
| testing here is that it renders the tenant's own boats and nobody else's.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
});

/** An operator with a boat, a sailing today, and a booking on it. */
function tradingOperator(): User
{
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        Port::factory()->create();

        $vessel = Vessel::factory()->create(['name' => 'Θάλασσα']);

        $departure = Departure::factory()->for($vessel)->at('2026-09-08', '18:00')->withSeats(4)->create();

        Booking::factory()->create([
            'departure_id' => $departure->getKey(),
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Confirmed,
        ]);
    });

    return $owner;
}

it('draws today\'s boats on the dashboard', function (): void {
    $owner = tradingOperator();

    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(TodayAtSea::class)
        ->assertOk()
        ->assertSee('Θάλασσα');
});

it('never links crew to a page they are refused', function (): void {
    // Mike, 2026-09-23: *«ως πλήρωμα, πατάω πάνω σε ένα trip και μου βγάζει
    // forbidden»*. The next sailing's title was a link guarded by
    // `canViewAny()` — which crew pass, because TEN-8 lets them read departures
    // — pointing at the **edit** page, which needs `ManageCatalogue`, which they
    // do not have. Their own home page led to a 403.
    //
    // Asserted against the question the widget now asks — `canEdit`, on the
    // record, rather than `canViewAny` on the resource. Both roles are checked,
    // because a guard that refuses everybody would satisfy the crew half on its
    // own and take the link away from the people who need it.
    $owner = tradingOperator();
    $crew = OperatorUser::withRole(Role::Crew, tenant: $owner->tenant);

    $departure = Tenancy::forTenant($owner->tenant, fn (): Departure => Departure::query()->sole());

    tenancy()->initialize($owner->tenant);

    actingAs($crew);
    expect(DepartureResource::canEdit($departure))->toBeFalse()
        // …and the list itself stays open to them: this is about where a link
        // points, not about taking departures away from crew.
        ->and(DepartureResource::canViewAny())->toBeTrue();

    actingAs($owner);
    expect(DepartureResource::canEdit($departure))->toBeTrue();

    // And the widget itself: `DayByBoat` is the one that draws the next
    // sailing's name, and it is where the link was. Both roles again — the
    // owner's half is what proves the assertion is reading the right markup.
    $editUrl = DepartureResource::getUrl('edit', ['record' => $departure]);

    expect((string) Livewire::actingAs($crew)->test(DayByBoat::class)->assertOk()->html())
        ->not->toContain($editUrl)
        ->and((string) Livewire::actingAs($owner)->test(DayByBoat::class)->assertOk()->html())
        ->toContain($editUrl);
})->group('fast');

it('hides both widgets from an operator who has not started', function (): void {
    // No vessel, no product, no booking — `FirstSteps` owns this screen.
    $owner = OperatorUser::withRole(Role::Owner);

    tenancy()->initialize($owner->tenant);

    expect(TodayAtSea::canView())->toBeFalse()
        ->and(NeedsAttention::canView())->toBeFalse();
});

it('hides the decision list when there is nothing to decide', function (): void {
    $owner = tradingOperator();

    tenancy()->initialize($owner->tenant);

    // A panel permanently saying "nothing needs attention" is furniture, and
    // furniture is what somebody stops seeing before the morning it matters.
    expect(NeedsAttention::canView())->toBeFalse()
        ->and(TodayAtSea::canView())->toBeTrue();
});

it('shows the decision list the moment a sailing is short', function (): void {
    $owner = tradingOperator();

    Tenancy::forTenant($owner->tenant, function (): void {
        Departure::query()->first()?->forceFill([
            'status' => DepartureStatus::Scheduled,
            'min_pax' => 8,
            'seats_sold' => 2,
        ])->save();
    });

    tenancy()->initialize($owner->tenant);

    expect(NeedsAttention::canView())->toBeTrue();

    Livewire::actingAs($owner)
        ->test(NeedsAttention::class)
        ->assertOk()
        ->assertSee('8');
});

it('draws no other operator\'s boats', function (): void {
    $mine = OperatorUser::withRole(Role::Owner);
    $theirs = tradingOperator();

    Tenancy::forTenant($mine->tenant, function (): void {
        Vessel::factory()->create(['name' => 'Ποσειδών']);
        Departure::factory()->at('2026-09-08', '10:00')->withSeats(2)->create();
    });

    tenancy()->initialize($mine->tenant);

    Livewire::actingAs($mine)
        ->test(TodayAtSea::class)
        ->assertOk()
        ->assertDontSee('Θάλασσα');

    expect($theirs->exists)->toBeTrue();
});

it('shows the figures once setup is finished, even before the first booking', function (): void {
    // The state the demo database landed in: a boat, a published trip and a
    // generated calendar, and no bookings yet.
    //
    // "Never had a booking" alone kept `FirstSteps` applying, so the checklist
    // rendered with **every step already done** — an empty card — while still
    // suppressing the figures behind it. Two blank boxes, on the afternoon
    // somebody has just finished setting up.
    $owner = tradingOperator();

    Tenancy::forTenant($owner->tenant, function (): void {
        Booking::query()->delete();
    });

    tenancy()->initialize($owner->tenant);

    expect(FirstSteps::next())->toBeNull()
        ->and(FirstSteps::applies())->toBeFalse()
        // Six zeros are the honest screen here: they are waiting for a first
        // booking rather than missing a step.
        ->and(OperationsOverview::canView())->toBeTrue();
});
