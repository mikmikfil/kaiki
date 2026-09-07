<?php

declare(strict_types=1);

use App\Domain\Operations\Support\CalendarDay;
use App\Enums\BlockReason;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\App\Pages\Calendar;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| OPS-3, OPS-4: the fleet's day, and the buffer nobody could see
|--------------------------------------------------------------------------
|
| The arithmetic is the risk here and it is invisible when wrong. A bar drawn at
| `hours / 24` is in the wrong place by a whole hour on the two days a year the
| clocks move — which are also the two days most likely to carry an odd charter,
| and the error is a few pixels, so nobody notices until an operator sends a boat
| out at the wrong time.
|
| OPS-4 is the other half: the availability engine refuses an overlapping booking
| because of a turnaround the operator cannot see. Drawing it is what turns "this
| product is broken" into "ah, the boat needs an hour".
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-08 06:00:00');
});

function calendarAs(User $user): Testable
{
    tenancy()->initialize($user->tenant);

    return Livewire::actingAs($user)->test(Calendar::class);
}

it('draws every kind of occupation on the boat it belongs to', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        $boat = Vessel::factory()->create(['name' => 'Θάλασσα', 'turnaround_buffer_minutes' => 60]);
        $other = Vessel::factory()->create(['name' => 'Ποσειδών']);

        Departure::factory()->for($boat)->at('2026-07-08', '09:00', 240)->withSeats(6)->create();
        Departure::factory()->for($other)->at('2026-07-08', '14:00', 120)->withSeats(0)->create();

        VesselBlock::factory()->for($boat)->create([
            'starts_at_utc' => Carbon::parse('2026-07-08 16:00', 'Europe/Athens')->utc(),
            'ends_at_utc' => Carbon::parse('2026-07-08 20:00', 'Europe/Athens')->utc(),
            'local_date' => '2026-07-08',
            'local_end_date' => '2026-07-08',
            'is_all_day' => false,
            'reason' => BlockReason::Maintenance,
        ]);
    });

    $day = Tenancy::forTenant($user->tenant, fn (): CalendarDay => CalendarDay::for('2026-07-08', 'Europe/Athens'));

    // Addressed by name rather than by position: a product factory brings its
    // own vessel with it, so the fleet has more boats than this test made.
    $rowFor = function (string $name) use ($day): array {
        foreach ($day->rows as $row) {
            if ($row['vessel']->name === $name) {
                return $row['bars'];
            }
        }

        return [];
    };

    expect($rowFor('Θάλασσα'))->toHaveCount(2)
        // Sorted by when they start, which is how anybody reads a day.
        ->and($rowFor('Θάλασσα')[0]['kind'])->toBe('departure')
        ->and($rowFor('Θάλασσα')[1]['kind'])->toBe('block')
        // An **empty** departure is still on the calendar. AVL-10 says it
        // occupies nothing for booking purposes, and it is exactly the sailing
        // an operator is deciding whether to cancel.
        ->and($rowFor('Ποσειδών'))->toHaveCount(1);
});

it('positions a bar as a fraction of the day, not of twenty-four hours', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        $boat = Vessel::factory()->create(['turnaround_buffer_minutes' => 0]);

        // Noon to 18:00 on an ordinary 24-hour day: half way in, a quarter wide.
        Departure::factory()->for($boat)->at('2026-07-08', '12:00', 360)->withSeats(2)->create();
    });

    $bar = Tenancy::forTenant(
        $user->tenant,
        fn (): array => CalendarDay::for('2026-07-08', 'Europe/Athens')->rows[0]['bars'][0],
    );

    expect(round($bar['start'], 4))->toBe(0.5)
        ->and(round($bar['end'], 4))->toBe(0.75);
});

it('is not an hour out on the day the clocks go forward', function (): void {
    // 29 March 2026 in Athens is 23 hours long. A track that assumes 24 puts
    // every afternoon bar an hour to the left of where it belongs — a few
    // pixels, which is why nobody notices.
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        $boat = Vessel::factory()->create(['turnaround_buffer_minutes' => 0]);

        Departure::factory()->for($boat)->at('2026-03-29', '12:00', 60)->withSeats(2)->create();
    });

    $day = Tenancy::forTenant($user->tenant, fn (): CalendarDay => CalendarDay::for('2026-03-29', 'Europe/Athens'));

    $bars = [];

    foreach ($day->rows as $row) {
        if ($row['bars'] !== []) {
            $bars = $row['bars'];

            break;
        }
    }

    // The clocks go forward at 03:00, so local noon is **eleven** hours after
    // local midnight rather than twelve — and the bar starts at 11/23, which a
    // track assuming 24 hours would put at 12/24 = 0.5, an hour to the right.
    expect(round($bars[0]['start'], 4))->toBe(round(11 / 23, 4))
        // Twenty-three hours means twenty-four marks, first and last included.
        ->and($day->hours())->toHaveCount(24);
});

it('draws the turnaround from the vessel setting, and never from a stored value', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    $vessel = Tenancy::forTenant($user->tenant, function (): Vessel {
        $boat = Vessel::factory()->create(['turnaround_buffer_minutes' => 60]);

        Departure::factory()->for($boat)->at('2026-07-08', '09:00', 120)->withSeats(2)->create();

        return $boat;
    });

    $buffer = fn (): float => Tenancy::forTenant(
        $user->tenant,
        fn (): float => CalendarDay::for('2026-07-08', 'Europe/Athens')->rows[0]['bars'][0]['buffer'],
    );

    // One hour of a 24-hour day.
    expect(round($buffer(), 5))->toBe(round(60 / 1440, 5));

    // AVL-8: lowering the turnaround shortens the margin on the same row
    // immediately, because nothing about it was written down.
    Tenancy::forTenant($user->tenant, fn () => $vessel->update(['turnaround_buffer_minutes' => 30]));

    expect(round($buffer(), 5))->toBe(round(30 / 1440, 5));
});

it('shows an overnight charter on both of the days it covers', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        $boat = Vessel::factory()->create(['turnaround_buffer_minutes' => 0]);

        // 22:00 to 09:00 the next morning.
        Departure::factory()->for($boat)->at('2026-07-08', '22:00', 660)->withSeats(4)->create();
    });

    $first = Tenancy::forTenant($user->tenant, fn (): array => CalendarDay::for('2026-07-08', 'Europe/Athens')->rows[0]['bars']);
    $second = Tenancy::forTenant($user->tenant, fn (): array => CalendarDay::for('2026-07-09', 'Europe/Athens')->rows[0]['bars']);

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        // Clipped rather than dropped: it runs off the right of the first day
        // and onto the left of the second. A boat at sea must not read as free.
        ->and(round($first[0]['end'], 4))->toBe(1.0)
        ->and(round($second[0]['start'], 4))->toBe(0.0);
});

it('keeps a cancelled departure visible rather than making it vanish', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        $boat = Vessel::factory()->create();

        Departure::factory()->for($boat)->at('2026-07-08', '09:00')->cancelled()->create();
    });

    $bars = Tenancy::forTenant($user->tenant, fn (): array => CalendarDay::for('2026-07-08', 'Europe/Athens')->rows[0]['bars']);

    expect($bars)->toHaveCount(1)
        ->and($bars[0]['cancelled'])->toBeTrue();
});

it('lets an owner block a boat, through the action that owns the rules', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, fn (): Vessel => Vessel::factory()->create(['name' => 'Θάλασσα']));

    $vessel = Tenancy::forTenant($user->tenant, fn (): Vessel => Vessel::query()->firstOrFail());

    calendarAs($user)
        ->callAction('block', [
            'vessel' => $vessel->uuid,
            'starts_at' => '10:00',
            'ends_at' => '13:00',
            'reason' => BlockReason::Maintenance->value,
        ]);

    $block = Tenancy::forTenant($user->tenant, fn (): ?VesselBlock => VesselBlock::query()->first());

    expect($block)->not->toBeNull()
        ->and($block?->reason)->toBe(BlockReason::Maintenance)
        ->and($block?->starts_at_utc->setTimezone('Europe/Athens')->format('H:i'))->toBe('10:00')
        ->and($block?->ends_at_utc->setTimezone('Europe/Athens')->format('H:i'))->toBe('13:00');
});

it('lets a block cover a booked departure, and says what it covered', function (): void {
    // A boat that has broken down is blocked whether or not somebody has bought
    // a seat on it. Refusing would leave the operator with no way to say what
    // has happened; the warning is what sends them to cancel the trip properly.
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, function (): void {
        $boat = Vessel::factory()->create();

        Departure::factory()->for($boat)->at('2026-07-08', '11:00', 120)->withSeats(5)->create();
    });

    $vessel = Tenancy::forTenant($user->tenant, fn (): Vessel => Vessel::query()->firstOrFail());

    calendarAs($user)
        ->callAction('block', [
            'vessel' => $vessel->uuid,
            'starts_at' => '10:00',
            'ends_at' => '14:00',
            'reason' => BlockReason::Maintenance->value,
        ])
        ->assertNotified();

    expect(Tenancy::forTenant($user->tenant, fn (): int => VesselBlock::query()->count()))->toBe(1);
});

it('lets crew read the calendar and gives them nothing to block with', function (): void {
    // TEN-8 makes crew read-only, and `ViewDepartures` is theirs — a skipper
    // looking up what is sailing today is the point of the page. What they do
    // not have is `ManageBookings`, so the action that writes to the fleet's
    // calendar is not on their screen at all.
    $crew = OperatorUser::withRole(Role::Crew);

    actingAs($crew)->get('/app/calendar')->assertSuccessful();

    calendarAs($crew)->assertActionHidden('block');
});

it('lets an owner open the page and see their boats', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($user->tenant, fn (): Vessel => Vessel::factory()->create(['name' => 'Θάλασσα']));

    calendarAs($user)
        ->assertOk()
        ->assertSee('Θάλασσα')
        // OPS-4's explanation, on the page rather than in a manual.
        ->assertSee(__('calendar.key.buffer'));
});

it('shows who is on a departure, without a document number in sight', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    $departure = Tenancy::forTenant($user->tenant, function (): Departure {
        $boat = Vessel::factory()->create();
        $sailing = Departure::factory()->for($boat)->at('2026-07-08', '09:00')->withSeats(4)->create();

        Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Μαρία Παπαδοπούλου',
            'balance_cents' => 2500,
        ]);

        Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Cancelled,
            'guest_name' => 'Κάποιος Ακυρωμένος',
        ]);

        return $sailing;
    });

    calendarAs($user)
        ->mountAction('pax', ['departure' => $departure->uuid])
        ->assertSee('Μαρία Παπαδοπούλου')
        // A cancelled booking on the list is a person the crew would count and
        // wait for on the quay.
        ->assertDontSee('Κάποιος Ακυρωμένος');
});

it('walks a day at a time from today', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    calendarAs($user)
        ->assertSet('date', '2026-07-08')
        ->call('shiftDays', 1)
        ->assertSet('date', '2026-07-09')
        ->call('shiftDays', -3)
        ->assertSet('date', '2026-07-06')
        ->call('today')
        ->assertSet('date', '2026-07-08');
});
