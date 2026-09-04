<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AvailabilityScenarioBuilder;

/*
|--------------------------------------------------------------------------
| AVL-17 and ADR-0016 — the two days a year the clock lies
|--------------------------------------------------------------------------
|
| Europe/Athens moves forward on the last Sunday of March and back on the last
| Sunday of October. Both dates are named explicitly in every case here, because
| a test written against "a DST date" passes 363 days a year and proves nothing
| about the two that matter.
|
| The rule under test is AVL-17: duration is **absolute elapsed time**. A
| four-hour cruise is four hours of sea time whatever the clock does, so its
| wall-clock end moves by an hour across a transition — which is correct, and
| looks like a bug to anyone who has not read the requirement.
|
*/

function dstScenario(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-01-15 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

it('AVL-17: a departure across the spring forward keeps its elapsed duration', function (): void {
    dstScenario(function (): void {
        // 29 March 2026: 03:00 becomes 04:00. A four-hour cruise leaving at
        // 01:00 ends at 06:00 on the wall clock — five hours later by the
        // calendar and four hours of sea time, which is what the crew and the
        // vessel schedule care about.
        $scenario = AvailabilityScenarioBuilder::make()->lasting(240);
        $product = $scenario->product();

        $departure = $scenario->departure($product, '2026-03-29', '01:00');

        expect($departure->ends_at_utc->getTimestamp() - $departure->starts_at_utc->getTimestamp())
            ->toBe(240 * 60)
            ->and($departure->ends_at_utc->copy()->setTimezone('Europe/Athens')->format('H:i'))
            ->toBe('06:00');
    });
})->group('fast');

it('AVL-17: a departure across the autumn fall back keeps its elapsed duration', function (): void {
    dstScenario(function (): void {
        // 25 October 2026: 04:00 becomes 03:00. Four hours from 02:00 ends at
        // 05:00 on the clock — three hours later by the calendar.
        $scenario = AvailabilityScenarioBuilder::make()->lasting(240);
        $product = $scenario->product();

        $departure = $scenario->departure($product, '2026-10-25', '02:00');

        expect($departure->ends_at_utc->getTimestamp() - $departure->starts_at_utc->getTimestamp())
            ->toBe(240 * 60)
            ->and($departure->ends_at_utc->copy()->setTimezone('Europe/Athens')->format('H:i'))
            ->toBe('05:00');
    });
})->group('fast');

it('ADR-0016: the ambiguous local time takes the first occurrence, on 25 October 2026', function (): void {
    dstScenario(function (): void {
        // 03:30 happens twice that night. The ADR fixes the tie-break as the
        // **earlier** instant, still at +03:00 — and PHP's own answer is the
        // later one, which is the entire reason a resolver exists.
        $scenario = AvailabilityScenarioBuilder::make()->lasting(120);
        $product = $scenario->product();

        $departure = $scenario->departure($product, '2026-10-25', '03:30');

        expect($departure->starts_at_utc->toDateTimeString())->toBe('2026-10-25 00:30:00')
            ->and($departure->dst_ambiguous)->toBeTrue()
            // And it renders back as the time the operator typed.
            ->and($departure->local_time)->toBe('03:30:00');
    });
})->group('fast');

it('ADR-0016: a local time in the spring-forward gap cannot be created, on 29 March 2026', function (): void {
    dstScenario(function (): void {
        // Option A: never invent a departure at a time the operator did not
        // choose. The factory goes through the resolver, so even a fixture
        // meets the refusal.
        $scenario = AvailabilityScenarioBuilder::make();

        $scenario->departure($scenario->product(), '2026-03-29', '03:30');
    });
})->throws(LogicException::class)->group('fast');

it('AVL-4: an all-day block covers 25 hours on 25 October 2026', function (): void {
    dstScenario(function (): void {
        $block = VesselBlock::factory()->allDay('2026-10-25')->create();

        expect($block->window()->minutes())->toBe(25 * 60);
    });
})->group('fast');

it('AVL-4: an all-day block covers 23 hours on 29 March 2026', function (): void {
    dstScenario(function (): void {
        // The direction that matters: a block that assumed 24 hours would end
        // an hour *after* the day, which is harmless, while one that assumed 24
        // on the October day ends an hour early with the boat still ashore.
        $block = VesselBlock::factory()->allDay('2026-03-29')->create();

        expect($block->window()->minutes())->toBe(23 * 60);
    });
})->group('fast');

it('AVL-14: availability for the 25-hour day covers the whole 25 hours', function (): void {
    dstScenario(function (): void {
        // The engine's view of the same day. A departure at 23:30 local on the
        // fall-back date is inside it, and a day interval built by adding 86400
        // seconds would miss the last hour.
        $scenario = AvailabilityScenarioBuilder::make()->lasting(60);
        $product = $scenario->product();

        $scenario->departure($product, '2026-10-25', '23:30');

        expect($scenario->checkSeats($product, '2026-10-25')[0]->departures)->toHaveCount(1);
    });
})->group('fast');

it('AVL-17: a block across a transition is measured in elapsed time too', function (): void {
    dstScenario(function (): void {
        // The same rule from the block side, so a maintenance window does not
        // quietly end an hour early on one day a year.
        $block = VesselBlock::factory()->allDay('2026-10-24', '2026-10-26')->create();

        expect($block->window()->minutes())->toBe((24 + 25 + 24) * 60);
    });
})->group('fast');
