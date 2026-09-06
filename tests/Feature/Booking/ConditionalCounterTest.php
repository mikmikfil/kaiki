<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Booking\Actions\ConfirmBooking;
use App\Domain\Booking\Support\SeatCommitment;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Events\BookingConfirmed;
use App\Events\DepartureGuaranteed;
use App\Exceptions\CapacityExceeded;
use App\Exceptions\IllegalStateTransition;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| AVL-43.2: the portable guard, exercised where there is no parallelism
|--------------------------------------------------------------------------
|
| `SELECT … FOR UPDATE` is a no-op on SQLite, so `OversellConcurrencyTest` skips
| locally and the capacity invariant would go untested on the stack this project
| develops on — which is where the code is written and where a mistake is made.
|
| AVL-43.2 exists for exactly that: the seat counter is written with a `WHERE`
| clause that re-checks capacity **at write time**, and zero affected rows
| aborts. That runs on every driver, and a stale read can be arranged by hand
| without any parallelism at all — which is what these tests do.
|
*/

/** @return array{0: Tenant, 1: Departure} */
function counterScenario(int $capacity = 4, int $sold = 0, int $held = 0, int $minPax = 0): array
{
    $tenant = Tenant::factory()->create();

    $departure = Tenancy::forTenant($tenant, static fn (): Departure => Departure::factory()->create([
        'capacity' => $capacity,
        'seats_sold' => $sold,
        'seats_held' => $held,
        'min_pax' => $minPax,
    ]));

    return [$tenant, $departure];
}

it('refuses a write against a row that changed under a stale read', function (): void {
    [$tenant, $departure] = counterScenario(capacity: 2);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        // The stale read: this object still believes both seats are free.
        expect($departure->seatsAvailable())->toBe(2);

        // Somebody else takes them, exactly as a parallel transaction would.
        DB::table('departures')->where('id', $departure->getKey())->update(['seats_sold' => 2]);

        // The `WHERE` clause is evaluated against the row as it *is*, not as
        // this object remembers it. No lock, no parallelism, same refusal.
        expect(SeatCommitment::commit($departure, 1))->toBeFalse()
            ->and((int) DB::table('departures')->where('id', $departure->getKey())->value('seats_sold'))->toBe(2);
    });
})->group('fast');

it('counts held seats against capacity', function (): void {
    [$tenant, $departure] = counterScenario(capacity: 4, sold: 1, held: 2);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        // One seat free: four minus one sold minus two held. `CLAUDE.md`'s
        // first invariant, in the one statement that enforces it.
        expect(SeatCommitment::commit($departure, 2))->toBeFalse()
            ->and(SeatCommitment::commit($departure, 1))->toBeTrue();
    });
})->group('fast');

it('does not make a guest compete for the seats they are already holding', function (): void {
    [$tenant, $departure] = counterScenario(capacity: 2, sold: 0, held: 2);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        // Both seats held by this booking. Without passing its own hold in, the
        // guest is refused their own seats: counted once in `seats_held` and
        // once more in the two being requested.
        expect(SeatCommitment::commit($departure, 2, ownHeldSeats: 2))->toBeTrue();

        $departure->refresh();

        // Moved, not duplicated (BKG-9).
        expect($departure->seats_sold)->toBe(2)
            ->and($departure->seats_held)->toBe(0);
    });
})->group('fast');

it('gives seats back on release without ever going negative', function (): void {
    [$tenant, $departure] = counterScenario(capacity: 4, sold: 2);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        SeatCommitment::release($departure, 2);

        expect($departure->refresh()->seats_sold)->toBe(0);

        // Twice. A scheduled job retries, and a floor in SQL is what stops two
        // concurrent cancellations driving the counter below zero between a
        // read and a write.
        SeatCommitment::release($departure, 2);

        expect($departure->refresh()->seats_sold)->toBe(0);
    });
})->group('fast');

it('aborts the whole confirmation when the seats have gone', function (): void {
    [$tenant, $departure] = counterScenario(capacity: 1);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        $booking = Booking::factory()->forDeparture($departure)->withPax(1, 1)->holding($departure)->create();

        DB::table('departures')->where('id', $departure->getKey())->update(['seats_sold' => 1, 'seats_held' => 0]);

        expect(fn () => app(ConfirmBooking::class)($booking))->toThrow(CapacityExceeded::class);

        // Nothing partial survives: the status did not move and the counter is
        // untouched. That is what putting the guard inside the transaction buys.
        expect($booking->refresh()->status)->toBe(BookingStatus::Draft)
            ->and($departure->refresh()->seats_sold)->toBe(1);
    });
})->group('fast');

it('confirms, moves the seats and dispatches after commit', function (): void {
    Event::fake([BookingConfirmed::class, DepartureGuaranteed::class]);

    [$tenant, $departure] = counterScenario(capacity: 10, minPax: 0);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding($departure)->create();

        app(HoldSeats::class)($booking, $departure);

        app(ConfirmBooking::class)($booking);

        $booking->refresh();
        $departure->refresh();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->confirmed_at)->not->toBeNull()
            // The hold is over; the seats are sold rather than held.
            ->and($booking->hold_expires_at)->toBeNull()
            ->and($departure->seats_sold)->toBe(2)
            ->and($departure->seats_held)->toBe(0);
    });

    Event::assertDispatched(BookingConfirmed::class);
})->group('fast');

it('guarantees a departure the moment min_pax is met, and never takes it back', function (): void {
    Event::fake([BookingConfirmed::class, DepartureGuaranteed::class]);

    [$tenant, $departure] = counterScenario(capacity: 10, minPax: 2);

    Tenancy::forTenant($tenant, function () use ($departure): void {
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding($departure)->create();

        app(ConfirmBooking::class)($booking);

        // AVL-48, decided inside the transaction because the count it reads is
        // only true in there.
        expect($departure->refresh()->status)->toBe(DepartureStatus::Guaranteed);

        // AVL-49, marked RESOLVED: the seats going away does not retract a
        // promise already made to guests by email.
        SeatCommitment::release($departure, 2);

        expect($departure->refresh()->status)->toBe(DepartureStatus::Guaranteed);
    });

    Event::assertDispatched(DepartureGuaranteed::class);
})->group('fast');

it('refuses a transition the state machine does not allow', function (): void {
    [$tenant, $departure] = counterScenario();

    Tenancy::forTenant($tenant, function () use ($departure): void {
        $booking = Booking::factory()->forDeparture($departure)->withPax(1, 1)->create([
            'status' => BookingStatus::Expired,
        ]);

        // §4's convention: every Action asserts against the enum's own map, so
        // a double-delivered webhook cannot re-confirm an expired booking.
        expect(fn () => app(ConfirmBooking::class)($booking))
            ->toThrow(IllegalStateTransition::class);
    });
})->group('fast');
