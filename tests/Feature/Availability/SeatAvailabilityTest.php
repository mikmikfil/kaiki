<?php

declare(strict_types=1);

use App\Data\Availability\AvailabilityDayData;
use App\Data\Availability\AvailabilityRequestData;
use App\Data\Availability\DepartureAvailabilityData;
use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Enums\AvailabilityRejection;
use App\Enums\DepartureStatus;
use App\Enums\ProductStatus;
use App\Enums\TenantStatus;
use App\Enums\VesselStatus;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| The seven AVL-22 conditions — spec AVL-22 to AVL-29
|--------------------------------------------------------------------------
|
| One test per condition, each failing **in isolation** with the other six
| satisfied. That structure is the point: a suite where every failure case also
| happens to be cancelled, or also happens to be full, proves that *something*
| refuses the booking and not that the right thing does.
|
| The reported reason is the first that applies, in an order chosen so the guest
| hears the most actionable thing. A departure is usually failing several at
| once.
|
*/

function availabilityTenant(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-06-01 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

/**
 * A publishable per-seat trip with an adult band, an infant band and a plan.
 *
 * @param  array<string, mixed>  $overrides
 */
function availableProduct(array $overrides = []): Product
{
    $vessel = Vessel::factory()->create(['capacity_max' => 12, 'turnaround_buffer_minutes' => 60]);

    $product = Product::factory()->create(array_merge([
        'vessel_id' => $vessel->getKey(),
        'status' => ProductStatus::Active,
        'max_pax' => 10,
        'duration_minutes' => 240,
    ], $overrides));

    AgeBand::factory()->create(['product_id' => $product->getKey()]);
    AgeBand::factory()->infant()->create(['product_id' => $product->getKey()]);

    RatePlan::factory()->create(['product_id' => $product->getKey()]);

    return $product->load(['vessel', 'ageBands']);
}

function departureFor(Product $product, string $date = '2026-07-04', string $time = '09:00'): Departure
{
    return Departure::factory()->at($date, $time, (int) $product->duration_minutes)->create([
        'product_id' => $product->getKey(),
        'vessel_id' => $product->vessel_id,
        'capacity' => 10,
    ]);
}

/**
 * @param  array<string, int>  $pax
 * @return list<AvailabilityDayData>
 */
function checkAvailability(Product $product, array $pax = ['adult' => 2], string $from = '2026-07-04', string $to = '2026-07-04'): array
{
    return app(CheckSeatAvailability::class)(
        $product,
        AvailabilityRequestData::forRange($from, $to, $pax),
    );
}

/** @param list<AvailabilityDayData> $days */
function firstDeparture(array $days): ?DepartureAvailabilityData
{
    return $days[0]->departures[0] ?? null;
}

it('offers a departure when every condition holds', function (): void {
    availabilityTenant(function (): void {
        $product = availableProduct();
        departureFor($product);

        $line = firstDeparture(checkAvailability($product));

        expect($line?->available)->toBeTrue()
            ->and($line?->rejection)->toBeNull()
            ->and($line?->seatsRemaining)->toBe(10)
            ->and($line?->localTime)->toBe('09:00:00');
    });
})->group('fast');

it('refuses when the vessel window is not free', function (): void {
    availabilityTenant(function (): void {
        // AVL-22.1. A maintenance block is always an occupation, unlike an
        // empty departure.
        $product = availableProduct();
        departureFor($product);

        VesselBlock::factory()->between('2026-07-04', '10:00', '14:00')
            ->create(['vessel_id' => $product->vessel_id]);

        expect(firstDeparture(checkAvailability($product))?->rejection)
            ->toBe(AvailabilityRejection::VesselBusy);
    });
})->group('fast');

it('never offers a cancelled departure at all', function (): void {
    availabilityTenant(function (): void {
        // AVL-28: not "unavailable" — not a sailing. A guest shown a cancelled
        // departure greyed out would ring up to ask about it.
        $product = availableProduct();
        departureFor($product)->forceFill(['status' => DepartureStatus::Cancelled])->saveQuietly();

        $days = checkAvailability($product);

        expect($days[0]->departures)->toBe([])
            ->and($days[0]->hasAvailability())->toBeFalse();
    });
})->group('fast');

it('refuses when there are not enough counted seats', function (): void {
    availabilityTenant(function (): void {
        // AVL-22.3 with AVL-24: `capacity − seats_sold − seats_held`.
        $product = availableProduct();
        departureFor($product)->forceFill(['seats_sold' => 8, 'seats_held' => 1])->saveQuietly();

        $line = firstDeparture(checkAvailability($product, ['adult' => 2]));

        expect($line?->rejection)->toBe(AvailabilityRejection::NotEnoughSeats)
            ->and($line?->seatsRemaining)->toBe(1);
    });
})->group('fast');

it('counts held seats against availability, not only sold ones', function (): void {
    availabilityTenant(function (): void {
        // §2.4 and AVL-22.3 agree that the two are disjoint and additive. The
        // subset reading would offer a seat somebody is paying for right now.
        $product = availableProduct();
        departureFor($product)->forceFill(['seats_sold' => 0, 'seats_held' => 9])->saveQuietly();

        expect(firstDeparture(checkAvailability($product, ['adult' => 2]))?->rejection)
            ->toBe(AvailabilityRejection::NotEnoughSeats);
    });
})->group('fast');

it('ignores non-counting pax when measuring seats', function (): void {
    availabilityTenant(function (): void {
        // AVL-23. Two adults and two infants is two seats, and a departure with
        // two seats left can take them.
        $product = availableProduct();
        departureFor($product)->forceFill(['seats_sold' => 8])->saveQuietly();

        expect(firstDeparture(checkAvailability($product, ['adult' => 2, 'infant' => 2]))?->available)
            ->toBeTrue();
    });
})->group('fast');

it('refuses when the trip is not published', function (): void {
    availabilityTenant(function (): void {
        // AVL-22.6.
        $product = availableProduct(['status' => ProductStatus::Draft]);
        departureFor($product);

        expect(firstDeparture(checkAvailability($product))?->rejection)
            ->toBe(AvailabilityRejection::ProductNotActive);
    });
})->group('fast');

it('refuses when the vessel is out of service', function (): void {
    availabilityTenant(function (): void {
        // AVL-22.6, the other half.
        $product = availableProduct();
        departureFor($product);
        $product->vessel->update(['status' => VesselStatus::Maintenance]);

        expect(firstDeparture(checkAvailability($product->load('vessel')))?->rejection)
            ->toBe(AvailabilityRejection::VesselNotActive);
    });
})->group('fast');

it('refuses every date while the operator is read-only', function (): void {
    // AVL-22.7 and TEN-9: a lapsed subscription stops **new** bookings.
    // Existing ones and tokenised guest pages keep working, which is why this
    // is a condition here rather than middleware on the endpoint.
    Queue::fake();
    Carbon::setTestNow('2026-06-01 08:00:00');

    Tenancy::forTenant(Tenant::factory()->create([
        'timezone' => 'Europe/Athens',
        'status' => TenantStatus::ReadOnly,
    ]), function (): void {
        $product = availableProduct();
        departureFor($product);

        expect(firstDeparture(checkAvailability($product))?->rejection)
            ->toBe(AvailabilityRejection::TenantReadOnly);
    });

    Carbon::setTestNow();
})->group('fast');

it('refuses a party with nobody who takes a seat', function (): void {
    availabilityTenant(function (): void {
        // AVL-26, with its own code because the remedy is to add an adult
        // rather than to pick another date.
        $product = availableProduct();
        departureFor($product);

        expect(firstDeparture(checkAvailability($product, ['infant' => 2]))?->rejection)
            ->toBe(AvailabilityRejection::NoCountedPax);
    });
})->group('fast');

it('lets two empty departures share a boat and an hour', function (): void {
    availabilityTenant(function (): void {
        // AVL-10 and AVL-11: an empty departure is not an occupation, and an
        // operator legitimately schedules two trips at once and lets the
        // bookings decide.
        $product = availableProduct();
        $other = availableProduct(['vessel_id' => null]);
        $other->update(['vessel_id' => $product->vessel_id]);

        departureFor($product);
        departureFor($other->load('vessel'));

        expect(firstDeparture(checkAvailability($product))?->available)->toBeTrue()
            ->and(firstDeparture(checkAvailability($other))?->available)->toBeTrue();
    });
})->group('fast');

it('stops the others being sellable once one takes a seat', function (): void {
    availabilityTenant(function (): void {
        // AVL-11's second half, and the reason it is a read-time question
        // rather than a stored flag: nothing is cancelled.
        $product = availableProduct();
        $other = availableProduct();
        $other->update(['vessel_id' => $product->vessel_id]);

        departureFor($product)->forceFill(['seats_sold' => 1])->saveQuietly();
        $quiet = departureFor($other->load('vessel'));

        expect(firstDeparture(checkAvailability($other))?->rejection)
            ->toBe(AvailabilityRejection::VesselBusy)
            // Still standing, still scheduled.
            ->and($quiet->refresh()->status)->toBe(DepartureStatus::Scheduled);
    });
})->group('fast');

it('returns every requested date, including the empty ones', function (): void {
    availabilityTenant(function (): void {
        // A calendar widget paints a month and needs "no sailing" to look
        // different from "not asked about".
        $product = availableProduct();
        departureFor($product, '2026-07-04');

        $days = checkAvailability($product, ['adult' => 2], '2026-07-03', '2026-07-05');

        expect($days)->toHaveCount(3)
            ->and($days[0]->departures)->toBe([])
            ->and($days[1]->hasAvailability())->toBeTrue()
            ->and($days[2]->departures)->toBe([]);
    });
})->group('fast');

it('refuses a range longer than 62 days', function (): void {
    availabilityTenant(function (): void {
        // AVL-29, and refused rather than trimmed: a silently shortened
        // response looks complete, and the guest concludes the boat does not
        // sail in September.
        AvailabilityRequestData::forRange('2026-07-01', '2026-09-30');
    });
})->throws(ValidationException::class)->group('fast');

it('accepts a range of exactly 62 days', function (): void {
    availabilityTenant(function (): void {
        $request = AvailabilityRequestData::forRange('2026-07-01', '2026-08-31');

        expect($request->dates())->toHaveCount(62);
    });
})->group('fast');

it('carries the derived price-from figure on an available departure', function (): void {
    availabilityTenant(function (): void {
        // §1.9's stored figure rather than a per-date price resolution, which
        // would be three more queries and is #33's job.
        $product = availableProduct();
        $base = $product->ageBands()->where('is_base', true)->firstOrFail();

        $plan = RatePlan::query()->where('product_id', $product->getKey())->firstOrFail();
        $plan->prices()->create(['age_band_id' => $base->getKey(), 'price_cents' => 4500]);

        departureFor($product);

        expect(firstDeparture(checkAvailability($product->refresh()->load(['vessel', 'ageBands'])))?->priceFromCents)
            ->toBe(4500);
    });
})->group('fast');
