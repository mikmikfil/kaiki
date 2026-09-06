<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CompleteDepartures;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Events\BookingCompleted;
use App\Models\Departure;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\Booking\CheckInScenario;

/*
|--------------------------------------------------------------------------
| BKG-21: `ends_at_utc` plus three hours, and the clock change that must not move it
|--------------------------------------------------------------------------
|
| > A scheduled job transitions `checked_in` and `confirmed` bookings to
| > `completed` at `ends_at_utc` plus 3 hours, and transitions the departure to
| > `completed`.
|
| The DST pair is the reason this file exists. `ends_at_utc + 3h` is an instant
| plus a duration and is the same instant on either side of a clock change.
| Computing it as *"local finish time plus three hours, converted back"* looks
| equivalent, reads more naturally, and is wrong by an hour on the last Sunday
| in October — which is inside the Greek season, on a day boats are sailing.
|
| The other half is idempotence. This runs every hour, so a sweep that acted
| twice would fire `BookingCompleted` twice and every listener behind it.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('leaves a booking alone until the grace period has passed', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    // 09:00 start, four hours: ends 13:00 UTC. Completion is due at 16:00.
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), durationMinutes: 240);

    Carbon::setTestNow('2026-07-03 15:59:00');

    app(CompleteDepartures::class)();

    // A sunset cruise that came back late, a skipper who took the long way
    // round, a guest checked in from a phone with no signal until the boat
    // docked — all of them write to a booking after `ends_at_utc`, and a
    // booking that had already gone `completed` refuses the write.
    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });
})->group('fast');

it('completes at exactly ends_at_utc plus three hours', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), durationMinutes: 240);

    Carbon::setTestNow('2026-07-03 16:00:00');

    expect(app(CompleteDepartures::class)())->toBe(1);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Completed)
            ->and($booking->completed_at)->not->toBeNull();
    });
})->group('fast');

it('completes a confirmed booking that nobody ever scanned', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), durationMinutes: 240);

    Carbon::setTestNow('2026-07-03 16:30:00');

    app(CompleteDepartures::class)();

    // BKG-21 names both statuses. Small operators do not scan tickets on a
    // six-person day boat, and leaving those bookings `confirmed` forever would
    // give an operator a growing list of trips that apparently never ended.
    // Absence of a scan is not evidence of a no-show — BKG-23 makes that a
    // separate, explicit mark.
    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Completed)
            ->and($booking->no_show)->toBeFalse();
    });
})->group('fast');

it('completes a checked-in booking too', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    [$tenant, $booking] = CheckInScenario::sailing(
        Carbon::parse('2026-07-03 09:00:00'),
        durationMinutes: 240,
        status: BookingStatus::CheckedIn,
    );

    Carbon::setTestNow('2026-07-03 16:30:00');

    app(CompleteDepartures::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Completed);
    });
})->group('fast');

it('adds three hours to the instant, not to the wall clock, in summer', function (): void {
    Carbon::setTestNow('2026-10-24 06:00:00');

    // Saturday 24 October, the day *before* Greece leaves summer time.
    // Ends 12:00 UTC = 15:00 Athens. Due at 15:00 UTC = 18:00 Athens.
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-10-24 08:00:00'), durationMinutes: 240);

    Carbon::setTestNow('2026-10-24 14:59:00');
    app(CompleteDepartures::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });

    Carbon::setTestNow('2026-10-24 15:00:00');
    app(CompleteDepartures::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Completed);
    });
})->group('fast');

it('adds three hours to the instant, not to the wall clock, after the change', function (): void {
    Carbon::setTestNow('2026-10-25 06:00:00');

    // Sunday 25 October: Greece goes UTC+3 → UTC+2 at 04:00 local. The same
    // 08:00 UTC departure now finishes at 14:00 Athens rather than 15:00 — and
    // completion is still due at exactly 15:00 UTC.
    //
    // This is the pair that catches the mistake. An implementation that read
    // the local finish time, added three hours and converted back would be an
    // hour out here and correct in the test above, so neither test alone
    // proves anything.
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-10-25 08:00:00'), durationMinutes: 240);

    Carbon::setTestNow('2026-10-25 14:59:00');
    app(CompleteDepartures::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });

    Carbon::setTestNow('2026-10-25 15:00:00');
    app(CompleteDepartures::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Completed);
    });
})->group('fast');

it('is idempotent when the sweep runs twice', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), durationMinutes: 240);

    Carbon::setTestNow('2026-07-03 16:30:00');

    Event::fake([BookingCompleted::class]);

    expect(app(CompleteDepartures::class)())->toBe(1)
        // The second pass finds nothing, because the update is conditional on
        // the status it is leaving rather than a read-then-write.
        ->and(app(CompleteDepartures::class)())->toBe(0);

    // And so exactly one event, not two — which matters because every listener
    // behind it would otherwise run twice.
    Event::assertDispatchedTimes(BookingCompleted::class, 1);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Completed);
    });
})->group('fast');

it('completes the departure as well as the bookings on it', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), durationMinutes: 240);

    Carbon::setTestNow('2026-07-03 16:30:00');

    app(CompleteDepartures::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $departure = Departure::query()->findOrFail($booking->departure_id);

        expect($departure->status)->toBe(DepartureStatus::Completed)
            ->and($departure->completed_at)->not->toBeNull();
    });
})->group('fast');

it('leaves a cancelled departure cancelled', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), durationMinutes: 240);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        Departure::query()->whereKey($booking->departure_id)
            ->update(['status' => DepartureStatus::Cancelled->value]);
    });

    Carbon::setTestNow('2026-07-03 16:30:00');

    app(CompleteDepartures::class)();

    // A trip that did not sail did not complete. Folding the two together
    // would destroy the one number a weather-prone operator most wants.
    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(Departure::query()->findOrFail($booking->departure_id)->status)
            ->toBe(DepartureStatus::Cancelled);
    });
})->group('fast');

it('leaves a cancelled booking alone', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    [$tenant, $booking] = CheckInScenario::sailing(
        Carbon::parse('2026-07-03 09:00:00'),
        durationMinutes: 240,
        status: BookingStatus::Cancelled,
    );

    Carbon::setTestNow('2026-07-03 16:30:00');

    expect(app(CompleteDepartures::class)())->toBe(0);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled);
    });
})->group('fast');

it('sweeps every tenant, from no tenant context at all', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    [$first, $bookingA] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), durationMinutes: 240);
    [$second, $bookingB] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), durationMinutes: 240);

    Carbon::setTestNow('2026-07-03 16:30:00');

    // The scheduler runs this with nobody signed in and no subdomain. Rows are
    // found `withoutTenancy()` and each tenant is entered to act — the same
    // shape as the hold sweeper and `ExpireQuotes`.
    expect(app(CompleteDepartures::class)())->toBe(2);

    Tenancy::forTenant($first, function () use ($bookingA): void {
        expect($bookingA->refresh()->status)->toBe(BookingStatus::Completed);
    });

    Tenancy::forTenant($second, function () use ($bookingB): void {
        expect($bookingB->refresh()->status)->toBe(BookingStatus::Completed);
    });
})->group('fast');
