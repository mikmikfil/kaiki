<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Booking\Actions\CancelBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| CXL-9 for a booking that holds rather than commits
|--------------------------------------------------------------------------
|
| `CancelReleasesCapacityTest` has fifteen assertions and every one of them
| cancels a **confirmed** booking, giving back `seats_sold`. Nothing covered the
| other half: a `draft` booking holding seats through `HoldSeats`, cancelled
| before it ever committed.
|
| The gap was found on the platform-health screen rather than in the suite —
| departure 4817 on the development database carries `seats_held = 4` with no
| booking holding them, left by `KAI-FEMSQ`, a four-person draft cancelled on
| 2026-09-16. Four seats nobody can buy, and nothing to say why.
|
| It matters more now than it did then. ADR-0034's GetYourGuide channel creates
| exactly this shape — a draft holding seats while a guest pays on somebody
| else's website — and their `cancel-reservation` call ends it. A leak here is a
| leak on every abandoned OTA checkout.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-27 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Booking, 2: Departure} */
function heldBookingFixture(int $capacity = 12, int $pax = 4): array
{
    $tenant = Tenant::factory()->create();

    [$booking, $departure] = Tenancy::forTenant($tenant, static function () use ($capacity, $pax): array {
        $departure = Departure::factory()->create([
            'capacity' => $capacity,
            'seats_sold' => 0,
            'seats_held' => 0,
            'min_pax' => 0,
        ]);

        $booking = Booking::factory()
            ->forDeparture($departure)
            ->withPax($pax, $pax)
            ->create(['status' => BookingStatus::Draft]);

        // Through the permitted writer, so the counter arrives the way it does
        // in production rather than by a fixture setting it.
        app(HoldSeats::class)($booking, $departure);

        return [$booking->refresh(), $departure->refresh()];
    });

    return [$tenant, $booking, $departure];
}

it('holds the seats in the first place', function (): void {
    // The premise of every assertion below. If this ever stops being true the
    // others pass for the wrong reason.
    [, $booking, $departure] = heldBookingFixture(pax: 4);

    expect($departure->seats_held)->toBe(4)
        ->and($booking->hold_expires_at)->not->toBeNull();
})->group('fast');

it('gives held seats back when a draft booking is cancelled', function (): void {
    // The defect. `CancelBooking::releaseCapacity()` runs only when the
    // booking's status *commits* seats — `pending_payment` and up — so a draft
    // skips it, and the next line nulls `hold_expires_at` without recounting.
    // The seats are then held by nothing and `ReleaseHold` can no longer
    // repair it: it returns early on a null column, before the recount.
    [$tenant, $booking, $departure] = heldBookingFixture(pax: 4);

    Tenancy::forTenant($tenant, function () use ($booking, $departure): void {
        app(CancelBooking::class)($booking);

        expect($departure->refresh()->seats_held)->toBe(0);
    });
})->group('fast');

it('leaves another draft holding on the same departure alone', function (): void {
    // The recount reads live holds rather than subtracting this booking's pax,
    // which is what makes it right whatever drift preceded it. A neighbour
    // still holding must survive.
    [$tenant, $booking, $departure] = heldBookingFixture(capacity: 12, pax: 4);

    $neighbour = Tenancy::forTenant($tenant, static function () use ($departure): Booking {
        $other = Booking::factory()
            ->forDeparture($departure)
            ->withPax(2, 2)
            ->create(['status' => BookingStatus::Draft]);

        app(HoldSeats::class)($other, $departure);

        return $other->refresh();
    });

    expect($departure->refresh()->seats_held)->toBe(6);

    Tenancy::forTenant($tenant, function () use ($booking, $departure, $neighbour): void {
        app(CancelBooking::class)($booking);

        expect($departure->refresh()->seats_held)->toBe(2)
            ->and($neighbour->refresh()->hold_expires_at)->not->toBeNull();
    });
})->group('fast');

it('puts the seats back on sale, which is the point', function (): void {
    // The counter is a cache of what is holdable; what matters is that the
    // seats can be held again. Asserted through `HoldSeats` rather than through
    // arithmetic, because that is the path a guest takes.
    [$tenant, $booking, $departure] = heldBookingFixture(capacity: 4, pax: 4);

    Tenancy::forTenant($tenant, function () use ($booking, $departure): void {
        app(CancelBooking::class)($booking);

        $next = Booking::factory()
            ->forDeparture($departure)
            ->withPax(4, 4)
            ->create(['status' => BookingStatus::Draft]);

        app(HoldSeats::class)($next, $departure->refresh());

        expect($departure->refresh()->seats_held)->toBe(4)
            ->and($next->refresh()->hold_expires_at)->not->toBeNull();
    });
})->group('fast');

it('still gives back a confirmed booking seats, which never broke', function (): void {
    // The neighbouring behaviour, asserted here too so a fix to the held path
    // cannot quietly cost the committed one.
    [$tenant, $booking, $departure] = heldBookingFixture(pax: 3);

    Tenancy::forTenant($tenant, function () use ($booking, $departure): void {
        // Draft → confirmed moves the pax from held to sold.
        $booking->forceFill(['status' => BookingStatus::Confirmed])->save();
        $departure->forceFill(['seats_held' => 0, 'seats_sold' => 3])->save();

        app(CancelBooking::class)($booking->refresh());

        expect($departure->refresh()->seats_sold)->toBe(0)
            ->and($departure->seats_held)->toBe(0);
    });
})->group('fast');
