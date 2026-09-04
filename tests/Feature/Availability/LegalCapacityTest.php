<?php

declare(strict_types=1);

use App\Data\Availability\AvailabilityRequestData;
use App\Data\Availability\DepartureAvailabilityData;
use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Availability\Contracts\DeparturePersonsAboard;
use App\Enums\AvailabilityRejection;
use App\Enums\ProductStatus;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| The legal capacity check — spec AVL-25
|--------------------------------------------------------------------------
|
| Marked RESOLVED in the spec with its reasoning attached: *"the brief
| distinguishes legal `capacity_max` from booking capacity but does not connect
| them; conflating them would let a boat sail illegally full of infants."*
|
| So there are two checks with two error codes. A party of two adults and six
| infants is **two seats and eight people**: it passes the seat check on a boat
| with two seats left and fails the certificate on a boat licensed for six. The
| guest must be told the second thing, because "the boat is full" is false and
| gives them nothing to do.
|
| How many people are already aboard comes from `DeparturePersonsAboard`, which
| has no implementation until M2 — the rule ships now so that M2 adds a class
| rather than a check.
|
*/

function legalTenant(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-06-01 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

/** A boat licensed for `$certificate` people, selling `$seats` seats. */
function legalProduct(int $certificate, int $seats): Product
{
    $vessel = Vessel::factory()->create(['capacity_max' => $certificate, 'turnaround_buffer_minutes' => 60]);

    $product = Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'status' => ProductStatus::Active,
        'max_pax' => $seats,
        'duration_minutes' => 240,
    ]);

    AgeBand::factory()->create(['product_id' => $product->getKey()]);
    AgeBand::factory()->infant()->create(['product_id' => $product->getKey()]);
    RatePlan::factory()->create(['product_id' => $product->getKey()]);

    return $product->load(['vessel', 'ageBands']);
}

function legalDeparture(Product $product, int $capacity): Departure
{
    return Departure::factory()->at('2026-07-04', '09:00', 240)->create([
        'product_id' => $product->getKey(),
        'vessel_id' => $product->vessel_id,
        'capacity' => $capacity,
    ]);
}

/** @param array<string, int> $pax */
function legalCheck(Product $product, array $pax): ?DepartureAvailabilityData
{
    $days = app(CheckSeatAvailability::class)(
        $product,
        AvailabilityRequestData::forRange('2026-07-04', '2026-07-04', $pax),
    );

    return $days[0]->departures[0] ?? null;
}

it('refuses a party that fits the seats but not the certificate', function (): void {
    legalTenant(function (): void {
        // Six licensed, eight seats sold-able on paper. Two adults and six
        // infants is two seats — which fits — and eight people, which does not.
        $product = legalProduct(certificate: 6, seats: 8);
        legalDeparture($product, capacity: 8);

        $line = legalCheck($product, ['adult' => 2, 'infant' => 6]);

        expect($line?->available)->toBeFalse()
            // Not `NotEnoughSeats`: there are six seats free, and saying the
            // boat is full would be false.
            ->and($line?->rejection)->toBe(AvailabilityRejection::LegalCapacityExceeded);
    });
})->group('fast');

it('accepts the same seats with fewer people', function (): void {
    legalTenant(function (): void {
        $product = legalProduct(certificate: 6, seats: 8);
        legalDeparture($product, capacity: 8);

        expect(legalCheck($product, ['adult' => 2, 'infant' => 2])?->available)->toBeTrue();
    });
})->group('fast');

it('accepts a party exactly at the certificate', function (): void {
    legalTenant(function (): void {
        // Six people on a boat licensed for six is a full boat, not an
        // overloaded one.
        $product = legalProduct(certificate: 6, seats: 8);
        legalDeparture($product, capacity: 8);

        expect(legalCheck($product, ['adult' => 4, 'infant' => 2])?->available)->toBeTrue();
    });
})->group('fast');

it('refuses one person over the certificate', function (): void {
    legalTenant(function (): void {
        $product = legalProduct(certificate: 6, seats: 8);
        legalDeparture($product, capacity: 8);

        expect(legalCheck($product, ['adult' => 4, 'infant' => 3])?->rejection)
            ->toBe(AvailabilityRejection::LegalCapacityExceeded);
    });
})->group('fast');

it('reports the seat shortage first when both checks fail', function (): void {
    legalTenant(function (): void {
        // The order is chosen for usefulness: a guest who is told the boat is
        // full picks another date, which is the right move. Telling them about
        // a licence they cannot influence is not.
        $product = legalProduct(certificate: 6, seats: 8);
        legalDeparture($product, capacity: 2);

        expect(legalCheck($product, ['adult' => 4, 'infant' => 4])?->rejection)
            ->toBe(AvailabilityRejection::NotEnoughSeats);
    });
})->group('fast');

it('counts the people already aboard once M2 can say how many', function (): void {
    legalTenant(function (): void {
        // The contract has no implementation today, so it reports zero. Binding
        // a fake proves the rule is wired rather than merely written — which is
        // the difference between a check that ships and a check that is
        // rediscovered under time pressure in M2.
        $product = legalProduct(certificate: 6, seats: 8);
        legalDeparture($product, capacity: 8);

        app()->bind(CheckSeatAvailability::class, static fn (): CheckSeatAvailability => new CheckSeatAvailability([
            new class implements DeparturePersonsAboard
            {
                public function personsAboard(Departure $departure): int
                {
                    return 5;
                }
            },
        ]));

        // Five aboard plus two more is seven, on a boat licensed for six.
        expect(legalCheck($product, ['adult' => 2])?->rejection)
            ->toBe(AvailabilityRejection::LegalCapacityExceeded);
    });
})->group('fast');

it('reports zero aboard while nothing implements the contract', function (): void {
    legalTenant(function (): void {
        // The M1 state, asserted so that registering an implementation later is
        // a visible change rather than a silent one.
        $product = legalProduct(certificate: 6, seats: 8);
        legalDeparture($product, capacity: 8);

        expect(legalCheck($product, ['adult' => 6])?->available)->toBeTrue();
    });
})->group('fast');
