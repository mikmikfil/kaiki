<?php

declare(strict_types=1);

use App\Data\Availability\AvailabilityRequestData;
use App\Data\Availability\DepartureAvailabilityData;
use App\Domain\Availability\Actions\CheckSeatAvailability;
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
| Booking windows — spec AVL-19, AVL-20
|--------------------------------------------------------------------------
|
| The two rules are measured in **different units on purpose**, and that is the
| thing worth pinning:
|
| - `min_lead_time_hours` is **absolute hours from now**. An hour is an hour
|   whatever the clocks did last night.
| - `max_advance_days` is **calendar days in the tenant's timezone**. "Ninety
|   days ahead" is a date an operator points at on a calendar, not 2160 hours.
|
| Implementing either in the other's unit is wrong by an hour twice a year, and
| the direction that lets a late booking through is the expensive one. Time is
| frozen throughout (TST-9), because a boundary test against a moving clock is a
| test that fails once a year at three in the morning.
|
*/

function windowTenant(string $now, callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow($now);

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

/** A trip whose default rate plan carries the given window rules. */
function windowProduct(int $leadHours = 0, ?int $maxAdvanceDays = null): Product
{
    $vessel = Vessel::factory()->create(['capacity_max' => 12, 'turnaround_buffer_minutes' => 60]);

    $product = Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'status' => ProductStatus::Active,
        'max_pax' => 10,
        'duration_minutes' => 240,
    ]);

    AgeBand::factory()->create(['product_id' => $product->getKey()]);

    RatePlan::factory()->create([
        'product_id' => $product->getKey(),
        'min_lead_time_hours' => $leadHours,
        'max_advance_days' => $maxAdvanceDays,
    ]);

    return $product->load(['vessel', 'ageBands']);
}

function windowDeparture(Product $product, string $date, string $time = '09:00'): Departure
{
    return Departure::factory()->at($date, $time, 240)->create([
        'product_id' => $product->getKey(),
        'vessel_id' => $product->vessel_id,
        'capacity' => 10,
    ]);
}

function windowCheck(Product $product, string $date): ?DepartureAvailabilityData
{
    $days = app(CheckSeatAvailability::class)(
        $product,
        AvailabilityRequestData::forRange($date, $date, ['adult' => 2]),
    );

    return $days[0]->departures[0] ?? null;
}

it('accepts a departure exactly at the lead-time boundary', function (): void {
    // 09:00 Athens on the 4th is 06:00 UTC. With 24 hours of notice required,
    // 06:00 UTC on the 3rd is the last acceptable moment — and "exactly at"
    // must pass, or the boundary means "more than".
    windowTenant('2026-07-03 06:00:00', function (): void {
        $product = windowProduct(leadHours: 24);
        windowDeparture($product, '2026-07-04');

        expect(windowCheck($product, '2026-07-04')?->available)->toBeTrue();
    });
})->group('fast');

it('refuses one minute inside the lead time', function (): void {
    windowTenant('2026-07-03 06:01:00', function (): void {
        $product = windowProduct(leadHours: 24);
        windowDeparture($product, '2026-07-04');

        expect(windowCheck($product, '2026-07-04')?->rejection)
            ->toBe(AvailabilityRejection::LeadTimeTooShort);
    });
})->group('fast');

it('accepts one minute outside the lead time', function (): void {
    windowTenant('2026-07-03 05:59:00', function (): void {
        $product = windowProduct(leadHours: 24);
        windowDeparture($product, '2026-07-04');

        expect(windowCheck($product, '2026-07-04')?->available)->toBeTrue();
    });
})->group('fast');

it('measures the lead time in absolute hours across a DST change', function (): void {
    // The reason AVL-19 is hours and not days. The clocks go back on 25
    // October, so the 24 hours before a 09:00 departure on the 25th began at
    // 08:00 local on the 24th — an hour earlier on the wall clock than the
    // naive answer.
    windowTenant('2026-10-24 05:00:00', function (): void {
        $product = windowProduct(leadHours: 24);
        windowDeparture($product, '2026-10-25');

        // 09:00 local on the 25th is 07:00 UTC; now is 05:00 UTC, so 26 hours
        // remain and the booking stands.
        expect(windowCheck($product, '2026-10-25')?->available)->toBeTrue();
    });
})->group('fast');

it('takes any booking when no lead time is set', function (): void {
    windowTenant('2026-07-04 05:59:00', function (): void {
        $product = windowProduct(leadHours: 0);
        windowDeparture($product, '2026-07-04');

        expect(windowCheck($product, '2026-07-04')?->available)->toBeTrue();
    });
})->group('fast');

it('accepts a departure exactly at the advance-days boundary', function (): void {
    // Thirty calendar days from the 1st is the 31st, and the 31st is included.
    windowTenant('2026-07-01 08:00:00', function (): void {
        $product = windowProduct(maxAdvanceDays: 30);
        windowDeparture($product, '2026-07-31');

        expect(windowCheck($product, '2026-07-31')?->available)->toBeTrue();
    });
})->group('fast');

it('refuses one day beyond the advance limit', function (): void {
    windowTenant('2026-07-01 08:00:00', function (): void {
        $product = windowProduct(maxAdvanceDays: 30);
        windowDeparture($product, '2026-08-01');

        expect(windowCheck($product, '2026-08-01')?->rejection)
            ->toBe(AvailabilityRejection::TooFarAhead);
    });
})->group('fast');

it('accepts one day inside the advance limit', function (): void {
    windowTenant('2026-07-01 08:00:00', function (): void {
        $product = windowProduct(maxAdvanceDays: 30);
        windowDeparture($product, '2026-07-30');

        expect(windowCheck($product, '2026-07-30')?->available)->toBeTrue();
    });
})->group('fast');

it('measures the advance limit from the tenant day, not the server day', function (): void {
    // 22:30 UTC on the 1st is already 01:30 on the 2nd in Athens. A limit
    // measured from the server's "today" would give the operator an extra day,
    // silently, for three hours every night.
    windowTenant('2026-07-01 22:30:00', function (): void {
        $product = windowProduct(maxAdvanceDays: 30);
        windowDeparture($product, '2026-08-01');

        // Thirty days from 2 July is 1 August, so this is the boundary rather
        // than beyond it.
        expect(windowCheck($product, '2026-08-01')?->available)->toBeTrue();
    });
})->group('fast');

it('books as far ahead as it likes when no limit is set', function (): void {
    windowTenant('2026-07-01 08:00:00', function (): void {
        $product = windowProduct(maxAdvanceDays: null);
        windowDeparture($product, '2026-08-25');

        expect(windowCheck($product, '2026-08-25')?->available)->toBeTrue();
    });
})->group('fast');
