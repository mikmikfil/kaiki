<?php

declare(strict_types=1);

use App\Data\Availability\AvailabilityRequestData;
use App\Domain\Availability\Actions\CheckVesselAvailability;
use App\Enums\ProductStatus;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| The turnaround, from the charter side — spec AVL-7, AVL-8
|--------------------------------------------------------------------------
|
| The same predicate `Window` already owns, exercised through the whole
| service — because a buffer that is right in a unit test and dropped somewhere
| in the availability path is a boat sold twice with an hour to swap over.
|
| A gap exactly equal to the buffer is legal; one minute less is not. And
| changing the vessel's setting changes the answer immediately, with no stored
| timestamp touched (AVL-8).
|
*/

function bufferTenant(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-06-01 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

/** A charter at `$startTime`, on a boat whose morning sailing is already sold. */
function charterAfterSoldDeparture(string $startTime, int $buffer = 60): bool
{
    $vessel = Vessel::factory()->create(['capacity_max' => 12, 'turnaround_buffer_minutes' => $buffer]);

    $shared = Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'duration_minutes' => 240,
        'max_pax' => 10,
    ]);

    // 09:00–13:00 local, with a seat sold, so it is a genuine occupation.
    Departure::factory()->at('2026-07-04', '09:00', 240)->create([
        'product_id' => $shared->getKey(),
        'vessel_id' => $vessel->getKey(),
    ])->forceFill(['seats_sold' => 2])->saveQuietly();

    $charter = Product::factory()->perVessel()->create([
        'vessel_id' => $vessel->getKey(),
        'status' => ProductStatus::Active,
        'duration_minutes' => 120,
        'default_start_time' => $startTime,
        'flexible_start' => false,
    ])->load('vessel');

    $windows = app(CheckVesselAvailability::class)(
        $charter,
        AvailabilityRequestData::forRange('2026-07-04', '2026-07-04'),
    );

    return $windows[0]->available;
}

it('offers a charter a full turnaround after the morning sailing', function (): void {
    bufferTenant(function (): void {
        // The sailing ends at 13:00; with sixty minutes of turnaround, 14:00 is
        // the first legal start. "Exactly at" must pass, or the buffer means
        // "more than".
        expect(charterAfterSoldDeparture('14:00'))->toBeTrue();
    });
})->group('fast');

it('refuses one minute inside the turnaround', function (): void {
    bufferTenant(function (): void {
        expect(charterAfterSoldDeparture('13:59'))->toBeFalse();
    });
})->group('fast');

it('counts the buffer once rather than once per side', function (): void {
    bufferTenant(function (): void {
        // Padding both windows would demand a two-hour gap between two
        // one-hour-buffered occupations, and 14:30 would read as a conflict
        // when the spec says it is not.
        expect(charterAfterSoldDeparture('14:30'))->toBeTrue();
    });
})->group('fast');

it('follows the vessel setting rather than the platform default', function (): void {
    bufferTenant(function (): void {
        // AVL-8: read at query time from the current setting. With fifteen
        // minutes of turnaround, 13:15 is legal — and it was not a moment ago.
        expect(charterAfterSoldDeparture('13:15', buffer: 15))->toBeTrue()
            ->and(charterAfterSoldDeparture('13:15', buffer: 60))->toBeFalse();
    });
})->group('fast');

it('ignores the turnaround around an empty departure', function (): void {
    bufferTenant(function (): void {
        // AVL-10: an empty departure is not an occupation, so there is nothing
        // to turn around from. The buffer applies to occupations, not to rows.
        $vessel = Vessel::factory()->create(['capacity_max' => 12, 'turnaround_buffer_minutes' => 60]);

        $shared = Product::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'duration_minutes' => 240,
            'max_pax' => 10,
        ]);

        Departure::factory()->at('2026-07-04', '09:00', 240)->create([
            'product_id' => $shared->getKey(),
            'vessel_id' => $vessel->getKey(),
        ]);

        $charter = Product::factory()->perVessel()->create([
            'vessel_id' => $vessel->getKey(),
            'status' => ProductStatus::Active,
            'duration_minutes' => 120,
            'default_start_time' => '13:15',
            'flexible_start' => false,
        ])->load('vessel');

        $windows = app(CheckVesselAvailability::class)(
            $charter,
            AvailabilityRequestData::forRange('2026-07-04', '2026-07-04'),
        );

        expect($windows[0]->available)->toBeTrue();
    });
})->group('fast');
