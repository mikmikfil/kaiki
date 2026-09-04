<?php

declare(strict_types=1);

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
| AVL-7 — the turnaround boundary, end to end
|--------------------------------------------------------------------------
|
| The predicate is fixed by the spec:
|
|     A.start < B.end + buffer  and  B.start < A.end + buffer
|
| symmetric, buffer counted **once**. `WindowTest` proves the arithmetic; this
| file proves the *engine* still applies it after four layers of collection,
| filtering and preloading — because a buffer that is right in a unit test and
| dropped somewhere in the availability path is a boat sold twice with an hour
| to swap over.
|
| Both directions are asserted for every case, since a predicate that is right
| one way round and wrong the other passes half a suite.
|
*/

function bufferScenario(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-06-01 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

/**
 * Is a charter starting at `$charterStart` free, given a sold sailing
 * 09:00–13:00 local on the same boat?
 */
function charterIsFree(string $charterStart, int $buffer = 60): bool
{
    $scenario = AvailabilityScenarioBuilder::make()->onVessel(bufferMinutes: $buffer)->lasting(240);

    $shared = $scenario->sharedProductOnSameVessel();
    $scenario->departure($shared, '2026-07-04', '09:00', seatsSold: 2);

    $charter = AvailabilityScenarioBuilder::make();
    $charterProduct = Product::factory()->perVessel()->create([
        'vessel_id' => $scenario->vessel()->getKey(),
        'status' => ProductStatus::Active,
        'duration_minutes' => 120,
        'default_start_time' => $charterStart,
        'flexible_start' => false,
    ])->load('vessel');

    return $charter->checkVessel($charterProduct, '2026-07-04')[0]->available;
}

/**
 * The mirror image: is a **seat** on a later sailing offered, given a charter
 * block that ends at 13:00?
 */
function seatIsOffered(string $seatStart, int $buffer = 60): bool
{
    $scenario = AvailabilityScenarioBuilder::make()->onVessel(bufferMinutes: $buffer)->lasting(120);

    VesselBlock::factory()->between('2026-07-04', '09:00', '13:00')
        ->create(['vessel_id' => $scenario->vessel()->getKey()]);

    $product = $scenario->product();
    $scenario->departure($product, '2026-07-04', $seatStart);

    return $scenario->checkSeats($product, '2026-07-04')[0]->departures[0]->available;
}

it('AVL-7: a gap exactly equal to the buffer does not conflict', function (): void {
    bufferScenario(function (): void {
        // The sailing ends at 13:00 and the boat has had its full hour by
        // 14:00. Refusing this would make the buffer mean "more than", which is
        // not what the spec says.
        expect(charterIsFree('14:00'))->toBeTrue()
            // And the same boundary from the other side.
            ->and(seatIsOffered('14:00'))->toBeTrue();
    });
})->group('fast');

it('AVL-7: one minute less than the buffer conflicts', function (): void {
    bufferScenario(function (): void {
        expect(charterIsFree('13:59'))->toBeFalse()
            ->and(seatIsOffered('13:59'))->toBeFalse();
    });
})->group('fast');

it('AVL-7: one minute more than the buffer does not conflict', function (): void {
    bufferScenario(function (): void {
        expect(charterIsFree('14:01'))->toBeTrue()
            ->and(seatIsOffered('14:01'))->toBeTrue();
    });
})->group('fast');

it('AVL-7: the buffer is counted once, not once per side', function (): void {
    bufferScenario(function (): void {
        // The failure this guards: padding both windows requires a two-hour gap
        // between two one-hour-buffered occupations, so 14:30 would read as a
        // conflict — and an operator rings support about departures that will
        // not generate.
        expect(charterIsFree('14:30'))->toBeTrue()
            ->and(seatIsOffered('14:30'))->toBeTrue();
    });
})->group('fast');

it('AVL-7: the boundary moves with the vessel setting', function (int $buffer, string $start, bool $free): void {
    bufferScenario(function () use ($buffer, $start, $free): void {
        // AVL-8: read at query time, never baked into a stored timestamp.
        expect(charterIsFree($start, $buffer))->toBe($free);
    });
})->with([
    'fifteen minutes, legal at 13:15' => [15, '13:15', true],
    'fifteen minutes, illegal at 13:14' => [15, '13:14', false],
    'two hours, illegal at 14:30' => [120, '14:30', false],
    'two hours, legal at 15:00' => [120, '15:00', true],
    'no buffer at all, legal at 13:00' => [0, '13:00', true],
])->group('fast');

it('AVL-10: an empty departure imposes no turnaround at all', function (): void {
    bufferScenario(function (): void {
        // The buffer protects occupations, not rows. Nothing has to be turned
        // around from a sailing nobody booked.
        $scenario = AvailabilityScenarioBuilder::make()->onVessel(bufferMinutes: 60)->lasting(240);

        $shared = $scenario->sharedProductOnSameVessel();
        $scenario->departure($shared, '2026-07-04', '09:00');

        $charter = Product::factory()->perVessel()->create([
            'vessel_id' => $scenario->vessel()->getKey(),
            'status' => ProductStatus::Active,
            'duration_minutes' => 120,
            'default_start_time' => '13:00',
            'flexible_start' => false,
        ])->load('vessel');

        expect($scenario->checkVessel($charter, '2026-07-04')[0]->available)->toBeTrue();
    });
})->group('fast');
