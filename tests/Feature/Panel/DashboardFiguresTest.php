<?php

declare(strict_types=1);

use App\Domain\Operations\Support\DashboardFigures;
use App\Domain\Operations\Support\OperatingWeek;
use App\Enums\BookingStatus;
use App\Enums\GuestDetailsStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| OPS-1, OPS-2: the six figures, each defined once
|--------------------------------------------------------------------------
|
| Every expected value here is arithmetic a reader can check by hand, and every
| test would fail if somebody changed a figure's definition. That is the point:
| the failure mode of a dashboard is not an exception, it is a number that is
| wrong by a little and looks exactly like a number that is right.
|
| The one an operator will actually check is revenue. They reconcile it against
| their bank once; if it does not match they stop believing every other number
| on the page, and they do not tell anybody.
|
*/

beforeEach(function (): void {
    // A Wednesday, so the Monday boundary is somewhere behind us and the
    // following Monday somewhere ahead. Fixed, because every assertion here is
    // about which side of a boundary something falls on.
    Carbon::setTestNow('2026-07-08 10:00:00');
});

/** @return array{0: Tenant, 1: DashboardFigures} */
function dashboard(?string $timezone = null): array
{
    $tenant = Tenant::factory()->create(['timezone' => $timezone ?? 'Europe/Athens']);

    return [$tenant, new DashboardFigures($tenant->timezone)];
}

it('counts departures sailing today and tomorrow, with their passengers', function (): void {
    [$tenant, $figures] = dashboard();

    Tenancy::forTenant($tenant, function (): void {
        Departure::factory()->at('2026-07-08', '09:00')->withSeats(6)->create();
        Departure::factory()->at('2026-07-08', '17:00')->withSeats(4)->create();
        Departure::factory()->at('2026-07-09', '09:00')->withSeats(2)->create();
        // The day after tomorrow, and yesterday: neither is the question.
        Departure::factory()->at('2026-07-10', '09:00')->withSeats(9)->create();
        Departure::factory()->at('2026-07-07', '09:00')->withSeats(9)->create();
        // Cancelled, on a day that counts.
        Departure::factory()->at('2026-07-08', '20:00')->withSeats(3)->cancelled()->create();
    });

    $sailing = Tenancy::forTenant($tenant, fn (): array => $figures->todayAndTomorrow());

    expect($sailing)->toBe(['departures' => 3, 'pax' => 12]);
});

it('reads today in the operator timezone rather than the server one', function (): void {
    // 22:30 UTC on the 8th is 01:30 on the 9th in Athens: a departure at that
    // instant is *tomorrow* for the operator and *today* for a naive server.
    // The one that matters is the operator's, because they are the one deciding
    // what to pack the cooler for.
    [$tenant, $figures] = dashboard();

    Tenancy::forTenant($tenant, function (): void {
        Departure::factory()->at('2026-07-10', '01:00')->withSeats(5)->create();
    });

    Carbon::setTestNow('2026-07-08 22:30:00');

    // Local now is the 9th, so today and tomorrow are the 9th and the 10th, and
    // that 01:00 departure on the 10th is in.
    $sailing = Tenancy::forTenant($tenant, fn (): array => $figures->todayAndTomorrow());

    expect($sailing['departures'])->toBe(1);
});

it('counts a departure as at risk only when it is short and close', function (): void {
    [$tenant, $figures] = dashboard();

    Tenancy::forTenant($tenant, function (): void {
        // Short, and tomorrow. The one the operator has to decide about.
        Departure::factory()->at('2026-07-09', '09:00')->withSeats(2)->create(['min_pax' => 4]);
        // Short, but a fortnight away — that is just an empty boat in July.
        Departure::factory()->at('2026-07-22', '09:00')->withSeats(0)->create(['min_pax' => 4]);
        // Close, and full enough.
        Departure::factory()->at('2026-07-09', '17:00')->withSeats(4)->create(['min_pax' => 4]);
        // Close and short, but no minimum at all — a private charter, which
        // cannot be short of a minimum it does not have.
        Departure::factory()->at('2026-07-09', '12:00')->withSeats(1)->create(['min_pax' => 0]);
        // Close and short, and already cancelled.
        Departure::factory()->at('2026-07-09', '20:00')->withSeats(1)->cancelled()->create(['min_pax' => 4]);
    });

    expect(Tenancy::forTenant($tenant, fn (): int => $figures->atRiskDepartures()))->toBe(1);
});

it('counts pending passenger details on bookings that are going to happen, and not on holds', function (): void {
    [$tenant, $figures] = dashboard();

    Tenancy::forTenant($tenant, function (): void {
        Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'guest_details_status' => GuestDetailsStatus::Pending,
        ]);
        Booking::factory()->create([
            'status' => BookingStatus::CheckedIn,
            'guest_details_status' => GuestDetailsStatus::Pending,
        ]);
        // A fifteen-minute hold. Asking an operator to chase a passport from
        // somebody who has not booked is worse than not asking at all.
        Booking::factory()->create([
            'status' => BookingStatus::Draft,
            'guest_details_status' => GuestDetailsStatus::Pending,
        ]);
        Booking::factory()->create([
            'status' => BookingStatus::Cancelled,
            'guest_details_status' => GuestDetailsStatus::Pending,
        ]);
        // A product that never asks. This must not tick up for ever.
        Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'guest_details_status' => GuestDetailsStatus::NotRequired,
        ]);
    });

    expect(Tenancy::forTenant($tenant, fn (): int => $figures->pendingGuestDetails()))->toBe(2);
});

it('counts only quotes the guest has actually been sent', function (): void {
    [$tenant, $figures] = dashboard();

    Tenancy::forTenant($tenant, function (): void {
        Quote::factory()->create(['status' => QuoteStatus::Sent]);
        Quote::factory()->create(['status' => QuoteStatus::Sent]);
        // The operator's own unfinished work, which belongs on a to-do list and
        // not on a figure that reads as "waiting on somebody else".
        Quote::factory()->create(['status' => QuoteStatus::Draft]);
        Quote::factory()->create(['status' => QuoteStatus::Accepted]);
        Quote::factory()->create(['status' => QuoteStatus::Expired]);
    });

    expect(Tenancy::forTenant($tenant, fn (): int => $figures->pendingQuotes()))->toBe(2);
});

it('adds up what is owed, and leaves out what will never be collected', function (): void {
    [$tenant, $figures] = dashboard();

    Tenancy::forTenant($tenant, function (): void {
        Booking::factory()->create(['status' => BookingStatus::Confirmed, 'balance_cents' => 5000]);
        // Sailed with money still owed: the most collectable debt there is.
        Booking::factory()->create(['status' => BookingStatus::Completed, 'balance_cents' => 2500]);
        Booking::factory()->create(['status' => BookingStatus::Confirmed, 'balance_cents' => 0]);
        // Cancelled: nobody owes this.
        Booking::factory()->create(['status' => BookingStatus::Cancelled, 'balance_cents' => 9900]);
        // A hold, which is not a debt and disappears on its own.
        Booking::factory()->create(['status' => BookingStatus::Draft, 'balance_cents' => 12000]);
    });

    expect(Tenancy::forTenant($tenant, fn (): array => $figures->unpaidBalances()))
        ->toBe(['bookings' => 2, 'cents' => 7500]);
});

it('computes revenue as payments minus refunds, in the operator week starting Monday', function (): void {
    [$tenant, $figures] = dashboard();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create();

        // Monday 6 July at 00:30 Athens is Sunday 5 July at 21:30 UTC — inside
        // this week for the operator and outside it for a naive UTC boundary.
        Payment::factory()->for($booking)->create([
            'amount_cents' => 10000,
            'paid_at' => Carbon::parse('2026-07-05 21:30:00', 'UTC'),
        ]);

        Payment::factory()->for($booking)->create([
            'amount_cents' => 4000,
            'paid_at' => Carbon::parse('2026-07-07 09:00:00', 'UTC'),
        ]);

        // A refund given this week against money taken whenever: it reduces
        // this week, because that is what the bank will show.
        Payment::factory()->for($booking)->create([
            'kind' => PaymentKind::Refund,
            'amount_cents' => 1500,
            'paid_at' => Carbon::parse('2026-07-08 08:00:00', 'UTC'),
        ]);

        // Last week.
        Payment::factory()->for($booking)->create([
            'amount_cents' => 99999,
            'paid_at' => Carbon::parse('2026-06-30 09:00:00', 'UTC'),
        ]);

        // Started and never finished.
        Payment::factory()->for($booking)->create([
            'status' => PaymentStatus::Pending,
            'amount_cents' => 77777,
            'paid_at' => null,
        ]);
    });

    // 10000 + 4000 − 1500.
    expect(Tenancy::forTenant($tenant, fn (): int => $figures->revenueThisWeek()))->toBe(12500);
});

it('leaves test bookings out of every figure, and says so when there are any', function (): void {
    [$tenant, $figures] = dashboard();

    Tenancy::forTenant($tenant, function (): void {
        $test = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'balance_cents' => 5000,
            'guest_details_status' => GuestDetailsStatus::Pending,
            'is_test' => true,
        ]);

        Payment::factory()->for($test)->create([
            'amount_cents' => 5000,
            'paid_at' => Carbon::parse('2026-07-07 09:00:00', 'UTC'),
        ]);

        Quote::factory()->for($test)->create(['status' => QuoteStatus::Sent]);
    });

    Tenancy::forTenant($tenant, function () use ($figures): void {
        expect($figures->unpaidBalances())->toBe(['bookings' => 0, 'cents' => 0])
            ->and($figures->pendingGuestDetails())->toBe(0)
            ->and($figures->pendingQuotes())->toBe(0)
            ->and($figures->revenueThisWeek())->toBe(0)
            // Excluded *and said so*: silently dropping rows the operator can
            // see in their own bookings list is its own kind of wrong number.
            ->and($figures->hasTestBookings())->toBeTrue();
    });
});

it('shows another operator nothing of ours', function (): void {
    [$mine, $figures] = dashboard();
    [$theirs] = dashboard();

    Tenancy::forTenant($theirs, function (): void {
        Booking::factory()->create(['status' => BookingStatus::Confirmed, 'balance_cents' => 50000]);
        Departure::factory()->at('2026-07-08', '09:00')->withSeats(9)->create();
    });

    Tenancy::forTenant($mine, function () use ($figures): void {
        expect($figures->unpaidBalances()['cents'])->toBe(0)
            ->and($figures->todayAndTomorrow()['departures'])->toBe(0);
    });
});

it('starts the week on Monday whatever the application locale says', function (): void {
    $week = OperatingWeek::containing('Europe/Athens', '2026-07-08');

    expect($week->startLocalDate)->toBe('2026-07-06')
        ->and($week->hours())->toBe(168);
});

it('measures the week that loses an hour as 167, not 168', function (): void {
    // The last Sunday of March. A week built by adding seven days of seconds to
    // an instant is wrong here, in the direction that puts an hour of Sunday
    // night's takings into the following week.
    $week = OperatingWeek::containing('Europe/Athens', '2026-03-30');

    expect($week->startLocalDate)->toBe('2026-03-30')
        ->and($week->hours())->toBe(168);

    $spring = OperatingWeek::containing('Europe/Athens', '2026-03-26');

    expect($spring->startLocalDate)->toBe('2026-03-23')
        ->and($spring->hours())->toBe(167);
});

it('does not grow its query count with the size of the catalogue', function (): void {
    [$tenant, $figures] = dashboard();

    Tenancy::forTenant($tenant, function (): void {
        Departure::factory()->count(20)->at('2026-07-08', '09:00')->withSeats(2)->create();
        Booking::factory()->count(20)->create(['status' => BookingStatus::Confirmed, 'balance_cents' => 100]);
    });

    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Tenancy::forTenant($tenant, function () use ($figures): void {
        $figures->todayAndTomorrow();
        $figures->atRiskDepartures();
        $figures->pendingGuestDetails();
        $figures->pendingQuotes();
        $figures->unpaidBalances();
        $figures->revenueThisWeek();
        $figures->hasTestBookings();
    });

    // Seven figures, seven aggregates. A dashboard whose cost grows with the
    // catalogue is one that gets slower every month the operator succeeds.
    expect($queries)->toBe(7);
});
