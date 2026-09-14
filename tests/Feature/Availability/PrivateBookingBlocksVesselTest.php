<?php

declare(strict_types=1);

use App\Enums\BlockReason;
use App\Enums\BookingMode;
use App\Enums\CancelReason;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;

/*
 * AVL-35: a whole-boat booking takes the hull off every other product.
 *
 * The shape under test is the one the demo seed never produces and every real
 * operator has — **one boat, two products**. A shared trip and a private
 * charter on the same Lagoon 40, which is exactly how the first real catalogue
 * this was tried against is arranged. Before the listener existed, confirming
 * the charter left the shared departure selling seats on a boat that was
 * already sold.
 *
 * Functions rather than `beforeEach` and `$this->…`: Pest's dynamic test
 * properties are invisible to static analysis, and nothing else in this
 * directory uses them.
 */

/**
 * One hull, a charter and a shared trip both selling it.
 *
 * @return array{tenant: Tenant, vessel: Vessel, charter: Product, shared: Product}
 */
function oneBoatTwoProducts(): array
{
    $tenant = Tenant::factory()->create();

    return Tenancy::forTenant($tenant, function () use ($tenant): array {
        $vessel = Vessel::factory()->create(['tenant_id' => $tenant->id]);

        return [
            'tenant' => $tenant,
            'vessel' => $vessel,
            'charter' => Product::factory()->create([
                'tenant_id' => $tenant->id,
                'vessel_id' => $vessel->id,
                'mode' => BookingMode::PerVessel,
            ]),
            'shared' => Product::factory()->create([
                'tenant_id' => $tenant->id,
                'vessel_id' => $vessel->id,
                'mode' => BookingMode::PerSeat,
            ]),
        ];
    });
}

/** A booking on that boat, confirmed. */
function confirmBookingOn(Tenant $tenant, Product $product, Vessel $vessel): Booking
{
    return Tenancy::forTenant($tenant, function () use ($tenant, $product, $vessel): Booking {
        $booking = Booking::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'vessel_id' => $vessel->id,
        ]);

        event(new BookingConfirmed($booking->id, $tenant->id));

        return $booking->refresh();
    });
}

it('blocks the boat when a whole-boat booking is confirmed', function (): void {
    ['tenant' => $tenant, 'vessel' => $vessel, 'charter' => $charter] = oneBoatTwoProducts();

    $booking = confirmBookingOn($tenant, $charter, $vessel);

    Tenancy::forTenant($tenant, function () use ($booking, $vessel): void {
        $block = VesselBlock::query()->where('booking_id', $booking->id)->first();

        expect($block)->not->toBeNull()
            ->and($block->reason)->toBe(BlockReason::PrivateBooking)
            ->and($block->vessel_id)->toBe($vessel->id)
            // The trip's own window, not the whole day: an all-day block would
            // refuse an evening sailing a morning charter leaves room for.
            ->and($block->is_all_day)->toBeFalse()
            ->and($block->starts_at_utc->equalTo($booking->starts_at_utc))->toBeTrue()
            ->and($block->ends_at_utc->equalTo($booking->ends_at_utc))->toBeTrue();
    });
})->group('fast');

it('leaves the boat alone for a per-seat booking', function (): void {
    // A shared trip is *meant* to share the hull. Blocking here would take the
    // boat off sale the moment one person bought one seat.
    ['tenant' => $tenant, 'vessel' => $vessel, 'shared' => $shared] = oneBoatTwoProducts();

    $booking = confirmBookingOn($tenant, $shared, $vessel);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(VesselBlock::query()->where('booking_id', $booking->id)->exists())->toBeFalse();
    });
})->group('fast');

it('puts the boat back on sale when the booking is cancelled', function (): void {
    ['tenant' => $tenant, 'vessel' => $vessel, 'charter' => $charter] = oneBoatTwoProducts();

    $booking = confirmBookingOn($tenant, $charter, $vessel);

    event(new BookingCancelled($booking->id, $tenant->id, CancelReason::GuestRequest, 0));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(VesselBlock::query()->where('booking_id', $booking->id)->exists())->toBeFalse();
    });
})->group('fast');

it('keeps one block when a booking is confirmed twice', function (): void {
    // A re-confirmation, or a quote that had already put its own block on this
    // booking id, must not leave a second hold on a boat nobody can then sell.
    ['tenant' => $tenant, 'vessel' => $vessel, 'charter' => $charter] = oneBoatTwoProducts();

    $booking = confirmBookingOn($tenant, $charter, $vessel);
    event(new BookingConfirmed($booking->id, $tenant->id));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(VesselBlock::query()->where('booking_id', $booking->id)->count())->toBe(1);
    });
})->group('fast');

it('does not reach across tenants', function (): void {
    ['tenant' => $tenant, 'vessel' => $vessel, 'charter' => $charter] = oneBoatTwoProducts();

    $booking = confirmBookingOn($tenant, $charter, $vessel);
    $other = Tenant::factory()->create();

    // The same booking id, announced under somebody else's tenant, must find
    // nothing — the id is only unique within its own operator.
    event(new BookingCancelled($booking->id, $other->id, CancelReason::GuestRequest, 0));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(VesselBlock::query()->where('booking_id', $booking->id)->exists())->toBeTrue();
    });
})->group('fast');

it('takes the shared departure on the same boat out of sale', function (): void {
    // The whole point, end to end. `VesselBlockObserver` recomputes
    // `departures.is_blocked` whenever a block is saved or deleted, so the
    // listener gets this for free — but "for free" is the kind of claim that
    // should be a test rather than a sentence in a docblock. It is also what
    // caught the first version of this listener deleting through the query
    // builder, which is a mass delete and never fires the observer at all.
    ['tenant' => $tenant, 'vessel' => $vessel, 'charter' => $charter, 'shared' => $shared] = oneBoatTwoProducts();

    Tenancy::forTenant($tenant, function () use ($tenant, $vessel, $charter, $shared): void {
        $departure = Departure::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $shared->id,
            'vessel_id' => $vessel->id,
        ]);

        expect($departure->is_blocked)->toBeFalse();

        $booking = Booking::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $charter->id,
            'vessel_id' => $vessel->id,
            'starts_at_utc' => $departure->starts_at_utc,
            'ends_at_utc' => $departure->ends_at_utc,
            'local_date' => $departure->local_date,
        ]);

        event(new BookingConfirmed($booking->id, $tenant->id));

        expect($departure->refresh()->is_blocked)->toBeTrue();

        // …and back on sale when the charter falls through.
        event(new BookingCancelled($booking->id, $tenant->id, CancelReason::GuestRequest, 0));

        expect($departure->refresh()->is_blocked)->toBeFalse();
    });
})->group('fast');
