<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Availability\Actions\ReleaseHold;
use App\Domain\Availability\Support\HoldLock;
use App\Exceptions\HoldLockUnavailable;
use App\Exceptions\HoldRefused;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;

/*
 * Spec AVL-37, AVL-38. ADR-0005 Option A.
 *
 * The one thing this file exists to prove: **the hold is data and the lock is a
 * mutex**, and they are not the same object. ADR-0005 rejected a cache-resident
 * hold because its failure is silent — the cache restarts, every hold
 * evaporates, and the boat is sold twice with nothing in any log.
 *
 * So the central test flushes the cache mid-hold. On the rejected design it
 * would pass by doing nothing; here it has to still be holding afterwards.
 */

function holdTenant(): Tenant
{
    return Tenant::factory()->create();
}

/** A departure with room, and a draft booking for two of its seats. */
function draftHolding(Departure $departure, int $seats = 2): Booking
{
    return Booking::factory()
        ->forDeparture($departure)
        ->withPax($seats, $seats)
        ->holding($departure)
        ->create();
}

it('survives a cache flush, because the hold was never in the cache', function (): void {
    $tenant = holdTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 10]);
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding()->create();

        app(HoldSeats::class)($booking, $departure);

        expect($booking->refresh()->holdsSeats())->toBeTrue()
            ->and($departure->refresh()->seats_held)->toBe(2);

        // The scenario ADR-0005 rejected Option B over. On a cache-resident
        // hold this line releases the seats and nothing says so.
        Cache::flush();

        expect($booking->refresh()->holdsSeats())->toBeTrue()
            ->and($departure->refresh()->seats_held)->toBe(2)
            ->and($departure->seatsAvailable())->toBe(8);
    });
})->group('fast');

it('releases the lock as soon as the write is done, not for the whole hold', function (): void {
    $tenant = holdTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 10]);
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding()->create();

        app(HoldSeats::class)($booking, $departure);

        // The hold has fifteen minutes to run. The lock had five seconds and is
        // already gone — which is what lets the next guest book the remaining
        // seats immediately instead of queueing behind somebody's checkout.
        $lock = Cache::lock(HoldLock::forDeparture($departure->getKey()), 5);

        expect($lock->get())->toBeTrue();

        $lock->release();
    });
})->group('fast');

it('times out explicitly rather than hanging when another writer will not yield', function (): void {
    $tenant = holdTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 10]);
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding()->create();

        // Held by somebody else and not released. AVL-37.2 asks for an explicit
        // timeout error — waiting forever turns a contended seat into a request
        // that hangs until the web server kills it, which reads as a broken
        // site rather than as somebody being half a second faster.
        $other = Cache::lock(HoldLock::forDeparture($departure->getKey()), 30);
        $other->get();

        config(['kaiki.booking.hold_lock_wait_seconds' => 1]);

        expect(fn () => app(HoldSeats::class)($booking, $departure))
            ->toThrow(HoldLockUnavailable::class);

        $other->release();
    });
})->group('fast');

it('tells a contended guest to try again rather than that the boat is full', function (): void {
    app()->setLocale('el');

    $exception = HoldLockUnavailable::forKey('kaiki:hold:departure:1');

    // The seat may well still be free. A "sold out" message would send a guest
    // away from a booking they could still make.
    expect($exception->getMessage())->not->toBe('booking.hold.contended')
        ->and($exception->getMessage())->not->toBe('')
        // And the internal key never reaches the sentence (CNV-8).
        ->and($exception->getMessage())->not->toContain('kaiki:hold');
})->group('fast', 'i18n');

it('refuses a hold the departure cannot fit', function (): void {
    $tenant = holdTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 4, 'seats_sold' => 3]);
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding()->create();

        expect(fn () => app(HoldSeats::class)($booking, $departure))->toThrow(HoldRefused::class);
    });
})->group('fast');

it('counts an expired hold out before judging capacity', function (): void {
    $tenant = holdTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 4, 'seats_held' => 4]);

        // Four seats held by a booking that lapsed forty minutes ago, and the
        // sweeper has not run. Without the recount this guest is refused a boat
        // that is empty — refused by a queue backlog rather than by the boat.
        Booking::factory()->forDeparture($departure)->withPax(4, 4)->heldButExpired($departure)->create();

        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding()->create();

        app(HoldSeats::class)($booking, $departure);

        // And the counter is written from the recount, so the stale four is
        // corrected rather than added to.
        expect($departure->refresh()->seats_held)->toBe(2);
    });
})->group('fast');

it('recomputes the counter on release rather than decrementing it', function (): void {
    $tenant = holdTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 10, 'seats_held' => 99]);
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding($departure)->create();

        app(ReleaseHold::class)($booking);

        // A decrement would leave 97 — carrying forward whatever drift the
        // counter had. A recount is right whatever happened before it.
        expect($departure->refresh()->seats_held)->toBe(0)
            ->and($booking->refresh()->hold_expires_at)->toBeNull();
    });
})->group('fast');

it('is safe to release twice', function (): void {
    $tenant = holdTenant();

    Tenancy::forTenant($tenant, function (): void {
        $departure = Departure::factory()->create(['capacity' => 10]);
        $booking = Booking::factory()->forDeparture($departure)->withPax(2, 2)->holding($departure)->create();

        app(HoldSeats::class)($booking, $departure);

        app(ReleaseHold::class)($booking);
        app(ReleaseHold::class)($booking);

        // Every caller of this is a retry waiting to happen — a queued job that
        // runs twice, a guest clicking abandon twice. A decrement would drive
        // the counter negative; a recount cannot.
        expect($departure->refresh()->seats_held)->toBe(0);
    });
})->group('fast');
