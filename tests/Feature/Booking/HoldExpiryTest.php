<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\ExtendHold;
use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Booking\Actions\ExpireStaleHolds;
use App\Domain\Booking\Support\BookingHoldSource;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Events\BookingHoldExpired;
use App\Exceptions\HoldRefused;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
 * Spec AVL-38, AVL-39. ADR-0005.
 *
 * AVL-38 asks for expiry **twice over**, and the two halves are not a duplicate
 * — they fail in opposite directions:
 *
 *   the read side  a hold is gone the instant its timestamp passes, so a
 *                  backlogged queue can never cause an oversell
 *   the sweeper    the stored counter is brought back into line, so an
 *                  operator's dashboard is not showing seats held by nobody
 *
 * The load-bearing test in this file is the second one: it asserts the release
 * **with the sweeper never run**. That is what proves which half is the
 * guarantee, rather than merely describing it in a comment.
 */

function expiryTenant(): Tenant
{
    return Tenant::factory()->create();
}

it('treats a lapsed hold as released with the sweeper never run', function (): void {
    $tenant = expiryTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 4, 'seats_held' => 4]);

        Booking::factory()->forDeparture($departure)->withPax(4, 4)->heldButExpired($departure)->create();

        // Nothing has run. `departures.seats_held` still says four.
        expect($departure->refresh()->seats_held)->toBe(4);

        // The read corrects it on its own, which is the whole requirement.
        $expired = app(BookingHoldSource::class)->expiredHeldSeats([$departure]);

        $departure->withExpiredHeldSeats($expired[$departure->getKey()] ?? 0);

        expect($departure->liveSeatsHeld())->toBe(0)
            ->and($departure->seatsAvailable())->toBe(4);
    });
})->group('fast');

it('leaves a live hold alone', function (): void {
    $tenant = expiryTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 4, 'seats_held' => 2]);

        Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding($departure)->create();

        // The other direction, and the reason the correction is subtracted
        // rather than the counter recomputed on read: a fast sweeper must never
        // release a hold a read still counts.
        $expired = app(BookingHoldSource::class)->expiredHeldSeats([$departure]);

        $departure->withExpiredHeldSeats($expired[$departure->getKey()] ?? 0);

        expect($departure->liveSeatsHeld())->toBe(2)
            ->and($departure->seatsAvailable())->toBe(2);
    });
})->group('fast');

it('releases the seats and expires the booking when the sweeper runs', function (): void {
    Event::fake([BookingHoldExpired::class]);

    $tenant = expiryTenant();

    $booking = Tenancy::forTenant($tenant, function (): Booking {
        $departure = Departure::factory()->create(['capacity' => 4, 'seats_held' => 4]);

        return Booking::factory()->forDeparture($departure)->withPax(4, 4)->heldButExpired($departure)->create();
    });

    $released = app(ExpireStaleHolds::class)();

    expect($released)->toBe(1);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::Expired)
            ->and($booking->hold_expires_at)->toBeNull()
            ->and($booking->cancel_reason)->toBe(CancelReason::HoldExpired)
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_held)->toBe(0);
    });

    Event::assertDispatched(BookingHoldExpired::class);
})->group('fast');

it('runs across every tenant, because it is a platform job', function (): void {
    $tenants = [expiryTenant(), expiryTenant()];

    foreach ($tenants as $tenant) {
        Tenancy::forTenant($tenant, function (): void {
            $departure = Departure::factory()->create(['capacity' => 4, 'seats_held' => 2]);
            Booking::factory()->forDeparture($departure)->withPax(2, 2)->heldButExpired($departure)->create();
        });
    }

    // `bookings_hold_expiry_idx` leads with `status` rather than `tenant_id`
    // for exactly this query — the only index in that table that does.
    expect(app(ExpireStaleHolds::class)())->toBe(2);
})->group('fast');

it('leaves a live hold for the sweeper to find later', function (): void {
    $tenant = expiryTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 4, 'seats_held' => 2]);
        Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding($departure)->create();
    });

    expect(app(ExpireStaleHolds::class)())->toBe(0);
})->group('fast');

it('extends a live hold without making the guest compete for their own seats', function (): void {
    $tenant = expiryTenant();

    Tenancy::forTenant($tenant, function (): void {
        // A boat with room for exactly this party and nothing else. Extending
        // must not re-check capacity, or the guest is refused seats they are
        // already holding.
        $departure = Departure::factory()->create(['capacity' => 2]);
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding($departure)->create();

        app(HoldSeats::class)($booking, $departure);

        $before = $booking->refresh()->hold_expires_at;

        // `Carbon::setTestNow` rather than `$this->travel()`: inside a Pest
        // closure `$this` is a `TestCall` at analysis time, so the trait method
        // is not statically visible even though it resolves at runtime. Same
        // class of thing as the `withoutExceptionHandling()` helper — the
        // explicit form is what both the analyser and a reader can follow.
        Carbon::setTestNow(now()->addMinutes(5));

        app(ExtendHold::class)($booking, $departure);

        expect($booking->refresh()->hold_expires_at?->greaterThan($before))->toBeTrue();
    });
})->group('fast');

it('re-acquires a lapsed hold when the seats are still there', function (): void {
    $tenant = expiryTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 10]);
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->heldButExpired($departure)->create();

        app(ExtendHold::class)($booking, $departure);

        // AVL-39's happy path: the guest came back from lunch and the boat is
        // still half empty, so nothing is lost.
        expect($booking->refresh()->holdsSeats())->toBeTrue()
            ->and($departure->refresh()->seats_held)->toBe(2);
    });
})->group('fast');

it('reports HOLD_EXPIRED rather than sold-out when a lapsed hold cannot be re-taken', function (): void {
    $tenant = expiryTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 4, 'seats_sold' => 4]);
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->heldButExpired($departure)->create();

        // The two are different stories for the guest: "somebody was faster
        // than you" against "your session ran out while you were away". AVL-39
        // names the second, and it is the one that carries a fresh availability
        // payload so the widget can re-render without losing them.
        $refused = null;

        try {
            app(ExtendHold::class)($booking, $departure);
        } catch (HoldRefused $exception) {
            $refused = $exception;
        }

        // Captured rather than asserted with `$this->fail()`, which is not
        // statically visible inside a Pest closure — and asserting the instance
        // separately means "it threw nothing at all" fails with that sentence
        // rather than with a null-property error.
        expect($refused)->toBeInstanceOf(HoldRefused::class)
            ->and($refused?->reason)->toBe('hold_expired');
    });
})->group('fast');

it('never holds anything for a quote-mode product', function (): void {
    $tenant = expiryTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 10]);
        $booking = Booking::factory()
            ->forDeparture($departure)
            ->withPax(2, 2)
            ->holding($departure)
            ->create(['mode' => BookingMode::Quote]);

        // AVL-41. Enforced in the Action and not only at the call site, because
        // a rule enforced at the call site is one the second call site forgets.
        expect(fn () => app(HoldSeats::class)($booking, $departure))->toThrow(HoldRefused::class);
    });
})->group('fast');
