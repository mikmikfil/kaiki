<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\RecomputeDepartureBlockedFlags;
use App\Domain\Availability\Support\Window;
use App\Domain\Availability\VesselCalendar;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| `departures.is_blocked` — data-model §2.4, brief §5 rule 1
|--------------------------------------------------------------------------
|
| A **cache of a range query**, not a source of truth: the write path still
| re-checks `vessel_blocks`, and this exists so the read path and the panel
| calendar do not have to join. A bug here costs a wrong badge, not an
| overbooked boat — which is exactly why it recomputes rather than
| incrementally toggling. Toggling would be faster and would drift.
|
| The rule that matters most is what it does **not** do: a block landing on a
| departure with sold seats raises an operator alert and cancels nothing. A
| maintenance window typed with the wrong month would otherwise silently cancel
| a boat full of paying guests.
|
*/

function blockedTenant(callable $callback): mixed
{
    Queue::fake();

    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

/** A per-seat product on a boat with a known turnaround. */
function blockedProduct(int $buffer = 60): Product
{
    $vessel = Vessel::factory()->create(['capacity_max' => 30, 'turnaround_buffer_minutes' => $buffer]);

    return Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'max_pax' => 12,
        'duration_minutes' => 240,
    ]);
}

/**
 * A departure matching the product's own duration.
 *
 * The 240 is not decoration: the factory's default is 480, and a departure that
 * ran four hours longer than its product would overlap every block in this file
 * regardless of the buffer — which would make the buffer assertions pass for
 * the wrong reason.
 */
function departureAt(Product $product, string $date, string $time): Departure
{
    return Departure::factory()->at($date, $time, (int) $product->duration_minutes)->create([
        'product_id' => $product->getKey(),
        'vessel_id' => $product->vessel_id,
    ]);
}

it('sets the flag when a block lands on a departure', function (): void {
    blockedTenant(function (): void {
        $product = blockedProduct();
        $departure = departureAt($product, '2026-07-04', '09:00');

        expect($departure->is_blocked)->toBeFalse();

        VesselBlock::factory()->between('2026-07-04', '10:00', '14:00')
            ->create(['vessel_id' => $product->vessel_id]);

        expect($departure->refresh()->is_blocked)->toBeTrue();
    });
})->group('fast');

it('clears the flag when the block is deleted', function (): void {
    blockedTenant(function (): void {
        // The delete hook recomputes from the block's **former** window: after
        // the row is gone there is nothing to ask, and the departures it was
        // blocking are exactly the ones whose flag must now come off.
        $product = blockedProduct();
        $departure = departureAt($product, '2026-07-04', '09:00');

        $block = VesselBlock::factory()->between('2026-07-04', '10:00', '14:00')
            ->create(['vessel_id' => $product->vessel_id]);

        expect($departure->refresh()->is_blocked)->toBeTrue();

        $block->delete();

        expect($departure->refresh()->is_blocked)->toBeFalse();
    });
})->group('fast');

it('leaves a departure alone when the block is on another boat', function (): void {
    blockedTenant(function (): void {
        $product = blockedProduct();
        $departure = departureAt($product, '2026-07-04', '09:00');

        VesselBlock::factory()->between('2026-07-04', '10:00', '14:00')->create();

        expect($departure->refresh()->is_blocked)->toBeFalse();
    });
})->group('fast');

it('respects the turnaround buffer when deciding', function (): void {
    blockedTenant(function (): void {
        // The departure runs 09:00–13:00 local with a 60-minute turnaround, so
        // a block starting at 13:30 still conflicts and one at 14:00 does not.
        $product = blockedProduct(60);
        $departure = departureAt($product, '2026-07-04', '09:00');

        $tight = VesselBlock::factory()->between('2026-07-04', '13:30', '15:00')
            ->create(['vessel_id' => $product->vessel_id]);

        expect($departure->refresh()->is_blocked)->toBeTrue();

        $tight->delete();

        VesselBlock::factory()->between('2026-07-04', '14:00', '15:00')
            ->create(['vessel_id' => $product->vessel_id]);

        expect($departure->refresh()->is_blocked)->toBeFalse();
    });
})->group('fast');

it('changes availability when the buffer changes, without rewriting a row', function (): void {
    blockedTenant(function (): void {
        // AVL-8: the buffer is applied at query time from the current vessel
        // setting and never baked into stored timestamps. Storing padded times
        // would corrupt the guest-facing schedule and turn a buffer change into
        // a data migration.
        $product = blockedProduct(60);
        $departure = departureAt($product, '2026-07-04', '09:00');
        $vessel = $product->vessel;

        VesselBlock::factory()->between('2026-07-04', '13:30', '15:00')
            ->create(['vessel_id' => $vessel->getKey()]);

        $storedStart = $departure->refresh()->starts_at_utc->toDateTimeString();
        $storedEnd = $departure->ends_at_utc->toDateTimeString();

        expect($departure->is_blocked)->toBeTrue();

        // Halve the turnaround: the same two windows no longer conflict.
        $vessel->update(['turnaround_buffer_minutes' => 15]);

        app(RecomputeDepartureBlockedFlags::class)->forVesselWindow(
            $vessel->refresh(),
            Window::of($departure->starts_at_utc, $departure->ends_at_utc),
        );

        $departure->refresh();

        expect($departure->is_blocked)->toBeFalse()
            // Nothing stored moved.
            ->and($departure->starts_at_utc->toDateTimeString())->toBe($storedStart)
            ->and($departure->ends_at_utc->toDateTimeString())->toBe($storedEnd);
    });
})->group('fast');

it('reports a sold departure instead of cancelling it', function (): void {
    blockedTenant(function (): void {
        // §2.4 and brief §5 rule 1. The operator decides; the flag and the
        // alert give them what they need to.
        $product = blockedProduct();
        $departure = departureAt($product, '2026-07-04', '09:00');
        $departure->forceFill(['seats_sold' => 4])->saveQuietly();

        $block = VesselBlock::factory()->between('2026-07-04', '10:00', '14:00')
            ->create(['vessel_id' => $product->vessel_id]);

        $affected = app(RecomputeDepartureBlockedFlags::class)($block);

        expect($affected)->toHaveCount(1)
            ->and($departure->refresh()->is_blocked)->toBeTrue()
            // Nothing cancelled, nothing unsold.
            ->and($departure->status->isSellable())->toBeTrue()
            ->and($departure->seats_sold)->toBe(4);
    });
})->group('fast');

it('reports a departure with only held seats too', function (): void {
    blockedTenant(function (): void {
        // A hold is somebody on the payment page. Blocking the boat under them
        // is the same problem arriving a few minutes earlier.
        $product = blockedProduct();
        $departure = departureAt($product, '2026-07-04', '09:00');
        $departure->forceFill(['seats_held' => 2])->saveQuietly();

        $block = VesselBlock::factory()->between('2026-07-04', '10:00', '14:00')
            ->create(['vessel_id' => $product->vessel_id]);

        expect(app(RecomputeDepartureBlockedFlags::class)($block))->toHaveCount(1);
    });
})->group('fast');

it('says nothing about an empty departure', function (): void {
    blockedTenant(function (): void {
        // The flag still goes on — the panel should badge it — but there is
        // nobody to warn about, and an alert on every empty departure is an
        // alert the operator learns to dismiss.
        $product = blockedProduct();
        departureAt($product, '2026-07-04', '09:00');

        $block = VesselBlock::factory()->between('2026-07-04', '10:00', '14:00')
            ->create(['vessel_id' => $product->vessel_id]);

        expect(app(RecomputeDepartureBlockedFlags::class)($block))->toHaveCount(0);
    });
})->group('fast');

it('counts a block as an occupation of the boat', function (): void {
    blockedTenant(function (): void {
        // What #30 and #31 will ask. An empty departure is not an occupation
        // (AVL-10); a block always is.
        $product = blockedProduct();
        $vessel = $product->vessel;

        $window = Window::of(
            Carbon::parse('2026-07-04 09:00:00', 'UTC'),
            Carbon::parse('2026-07-04 11:00:00', 'UTC'),
        );

        expect(VesselCalendar::isFree($vessel, $window))->toBeTrue();

        VesselBlock::factory()->between('2026-07-04', '12:00', '16:00')
            ->create(['vessel_id' => $vessel->getKey()]);

        expect(VesselCalendar::isFree($vessel->refresh(), $window))->toBeFalse();
    });
})->group('fast');
