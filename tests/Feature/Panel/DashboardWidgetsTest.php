<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\Role;
use App\Filament\App\Widgets\NeedsAttention;
use App\Filament\App\Widgets\TodayAtSea;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
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
