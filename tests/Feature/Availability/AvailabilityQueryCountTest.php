<?php

declare(strict_types=1);

use App\Data\Availability\AvailabilityDayData;
use App\Data\Availability\AvailabilityRequestData;
use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Enums\ProductStatus;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Five queries for a fortnight — spec NFR-7
|--------------------------------------------------------------------------
|
| *"Availability queries for a 14-day window use at most 5 database queries
| regardless of the number of departures returned."* The second half is the
| requirement; the first is the budget it has to fit in.
|
| The five:
|
| 1. every non-cancelled departure on the **vessel** across the range — the
|    product's own are filtered out of that set in PHP, because they are a
|    subset and a separate query would be a fifth of the allowance;
| 2. the vessel's blocks;
| 3. the product's active rate plans, for AVL-19 and AVL-20;
| 4 and 5. seasons and their ranges, so the plan for each date is chosen in PHP.
|
| A count is asserted rather than a duration: a benchmark is flaky and a count
| is exact. The second test is the one carrying the requirement — the same five
| for four times the departures.
|
*/

function queryTenant(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-06-01 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

function countedProduct(): Product
{
    $vessel = Vessel::factory()->create(['capacity_max' => 12, 'turnaround_buffer_minutes' => 60]);

    $product = Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'status' => ProductStatus::Active,
        'max_pax' => 10,
        'duration_minutes' => 240,
    ]);

    AgeBand::factory()->create(['product_id' => $product->getKey()]);
    RatePlan::factory()->create(['product_id' => $product->getKey()]);

    return $product->load(['vessel', 'ageBands']);
}

function seedDepartures(Product $product, string $from, int $days, int $perDay = 1): void
{
    $date = Carbon::parse($from);

    for ($day = 0; $day < $days; $day++) {
        for ($n = 0; $n < $perDay; $n++) {
            Departure::factory()
                ->at($date->toDateString(), sprintf('%02d:00', 8 + $n * 5), 240)
                ->create([
                    'product_id' => $product->getKey(),
                    'vessel_id' => $product->vessel_id,
                    'capacity' => 10,
                ]);
        }

        $date->addDay();
    }
}

/** @return array{0: int, 1: array<int, mixed>} */
function countQueries(Product $product, string $from, string $to): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $days = app(CheckSeatAvailability::class)(
        $product,
        AvailabilityRequestData::forRange($from, $to, ['adult' => 2]),
    );

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    return [count($queries), $days];
}

it('answers a 14-day window in five queries', function (): void {
    queryTenant(function (): void {
        $product = countedProduct();
        seedDepartures($product, '2026-07-01', days: 14);

        [$count, $days] = countQueries($product, '2026-07-01', '2026-07-14');

        expect($days)->toHaveCount(14)
            ->and($count)->toBeLessThanOrEqual(5);
    });
})->group('fast');

it('costs the same for four times the departures', function (): void {
    queryTenant(function (): void {
        // The half of NFR-7 that is the actual requirement. A version that
        // asked a question per departure would pass the test above with a
        // sparse calendar and fail a real operator's July.
        $product = countedProduct();
        seedDepartures($product, '2026-07-01', days: 14, perDay: 4);

        [$count, $days] = countQueries($product, '2026-07-01', '2026-07-14');

        $departures = array_sum(array_map(
            static fn (AvailabilityDayData $day): int => count($day->departures),
            $days,
        ));

        expect($departures)->toBe(56)
            ->and($count)->toBeLessThanOrEqual(5);
    });
})->group('fast');

it('costs the same for a 62-day window as for a fortnight', function (): void {
    queryTenant(function (): void {
        // AVL-29's maximum range. The queries are per **request**, not per day
        // — which is the property that makes a calendar month cheap.
        $product = countedProduct();
        seedDepartures($product, '2026-07-01', days: 62);

        [$count, $days] = countQueries($product, '2026-07-01', '2026-08-31');

        expect($days)->toHaveCount(62)
            ->and($count)->toBeLessThanOrEqual(5);
    });
})->group('fast');
