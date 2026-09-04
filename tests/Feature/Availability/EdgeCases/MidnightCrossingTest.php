<?php

declare(strict_types=1);

use App\Enums\BlockReason;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AvailabilityScenarioBuilder;

/*
|--------------------------------------------------------------------------
| AVL-15 — an occupation belongs to every local day it touches
|--------------------------------------------------------------------------
|
| A charter from 21:00 to 02:00 is one window and two calendar days. A query for
| a local day must return **every occupation overlapping the day interval**, not
| only the ones that start inside it — otherwise the boat looks free all morning
| on a day it is still at sea, and the second charter of the night is sold.
|
| The bug this guards is a `whereDate('local_date', ...)` somewhere in the
| availability path: correct for every ordinary sailing and wrong for exactly
| the ones that run past midnight.
|
*/

function midnightScenario(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-06-01 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

it('AVL-15: a charter crossing midnight occupies the boat on both days', function (): void {
    midnightScenario(function (): void {
        // 21:00 on the 4th to 02:00 on the 5th, as a block — the shape a
        // confirmed private booking takes in M2 (AVL-35).
        $scenario = AvailabilityScenarioBuilder::make()->onVessel(bufferMinutes: 0)->lasting(120);

        $block = VesselBlock::factory()->create([
            'vessel_id' => $scenario->vessel()->getKey(),
            'starts_at_utc' => Carbon::parse('2026-07-04 18:00:00', 'UTC'),
            'ends_at_utc' => Carbon::parse('2026-07-04 23:00:00', 'UTC'),
            'local_date' => '2026-07-04',
            'local_end_date' => '2026-07-05',
            'is_all_day' => false,
            'reason' => BlockReason::PrivateBooking,
        ]);

        $product = $scenario->product();

        // A sailing at 22:00 on the 4th — inside the night, same local day.
        $scenario->departure($product, '2026-07-04', '22:00');
        // And one at 01:00 on the 5th — inside the same night, next local day.
        $scenario->departure($product, '2026-07-05', '01:00');

        $fourth = $scenario->checkSeats($product, '2026-07-04')[0];
        $fifth = $scenario->checkSeats($product, '2026-07-05')[0];

        expect($block->exists)->toBeTrue()
            // Both refused, because the occupation spans the boundary and the
            // interval logic compares UTC instants rather than local dates.
            ->and($fourth->departures[0]->available)->toBeFalse()
            ->and($fifth->departures[0]->available)->toBeFalse();
    });
})->group('fast');

it('AVL-15: a charter query for the second day sees the night before', function (): void {
    midnightScenario(function (): void {
        // The same question from the whole-boat side: a guest asking about the
        // 5th must not be offered a morning the boat is still out.
        $scenario = AvailabilityScenarioBuilder::make()->onVessel(bufferMinutes: 0);

        VesselBlock::factory()->create([
            'vessel_id' => $scenario->vessel()->getKey(),
            'starts_at_utc' => Carbon::parse('2026-07-04 18:00:00', 'UTC'),
            'ends_at_utc' => Carbon::parse('2026-07-04 23:00:00', 'UTC'),
            'local_date' => '2026-07-04',
            'local_end_date' => '2026-07-05',
            'is_all_day' => false,
            'reason' => BlockReason::PrivateBooking,
        ]);

        $charter = Product::factory()->perVessel()->create([
            'vessel_id' => $scenario->vessel()->getKey(),
            'status' => ProductStatus::Active,
            'duration_minutes' => 120,
            // 01:00 local on the 5th is 22:00 UTC on the 4th — inside the block.
            'default_start_time' => '01:00',
            'flexible_start' => false,
        ])->load('vessel');

        expect($scenario->checkVessel($charter, '2026-07-05')[0]->available)->toBeFalse();
    });
})->group('fast');

it('AVL-15: the morning after a night charter is free again', function (): void {
    midnightScenario(function (): void {
        // The other half, and the one that stops an over-eager fix: a boat that
        // finished at 02:00 is available at 09:00, and a query that widened the
        // day interval too far would refuse it.
        $scenario = AvailabilityScenarioBuilder::make()->onVessel(bufferMinutes: 60)->lasting(240);

        VesselBlock::factory()->create([
            'vessel_id' => $scenario->vessel()->getKey(),
            'starts_at_utc' => Carbon::parse('2026-07-04 18:00:00', 'UTC'),
            'ends_at_utc' => Carbon::parse('2026-07-04 23:00:00', 'UTC'),
            'local_date' => '2026-07-04',
            'local_end_date' => '2026-07-05',
            'is_all_day' => false,
            'reason' => BlockReason::Manual,
        ]);

        $product = $scenario->product();
        $scenario->departure($product, '2026-07-05', '09:00');

        expect($scenario->checkSeats($product, '2026-07-05')[0]->departures[0]->available)->toBeTrue();
    });
})->group('fast');

it('AVL-14: a query for one local day returns only that day', function (): void {
    midnightScenario(function (): void {
        // The dual of the rule above: an occupation on the 6th must not appear
        // in the answer for the 5th, however wide the internal load window is.
        $scenario = AvailabilityScenarioBuilder::make();
        $product = $scenario->product();

        $scenario->departure($product, '2026-07-05', '09:00');
        $scenario->departure($product, '2026-07-06', '09:00');

        $days = $scenario->checkSeats($product, '2026-07-05');

        expect($days)->toHaveCount(1)
            ->and($days[0]->departures)->toHaveCount(1);
    });
})->group('fast');
