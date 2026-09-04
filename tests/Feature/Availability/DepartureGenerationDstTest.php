<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\GenerateDepartures;
use App\Domain\Availability\Support\DepartureReconciler;
use App\Domain\Availability\Support\WeekdayMask;
use App\Models\Departure;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Generation across the DST transitions — ADR-0016
|--------------------------------------------------------------------------
|
| Option A: *"never invents a departure at a time the operator did not choose,
| and never silently shifts one."* So on the spring-forward date the job creates
| nothing and tells the operator; on the fall-back date it takes the first
| occurrence and flags the row.
|
| Both are asserted by naming the calendar date. A rule at 03:30 is unusual and
| a night charter at 03:30 is not, and the failure — a departure an hour away
| from where everyone expects it — is discovered by a crew standing on a quay.
|
*/

function dstTenant(callable $callback): mixed
{
    Queue::fake();

    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

function dstRule(string $startTime, string $from, string $until): ScheduleRule
{
    $vessel = Vessel::factory()->create(['capacity_max' => 30]);
    $product = Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'max_pax' => 12,
        'duration_minutes' => 240,
    ]);

    return ScheduleRule::factory()->create([
        'product_id' => $product->getKey(),
        'weekday_mask' => WeekdayMask::DAILY,
        'start_time' => $startTime,
        'valid_from' => $from,
        'valid_until' => $until,
    ]);
}

it('skips the spring-forward date and records it', function (): void {
    dstTenant(function (): void {
        // 29 March 2026: 03:00 becomes 04:00, so 03:30 never happens.
        $rule = dstRule('03:30', '2026-03-28', '2026-03-30');

        $result = app(GenerateDepartures::class)($rule, Carbon::parse('2026-03-01', 'Europe/Athens'));

        expect($result->created)->toBe(2)
            ->and($result->dstSkippedDates)->toBe(['2026-03-29'])
            ->and($result->hasIssues())->toBeTrue()
            ->and(Departure::query()->where('local_date', '2026-03-29')->exists())->toBeFalse();
    });
})->group('fast');

it('shows the skipped date to the operator', function (): void {
    dstTenant(function (): void {
        // ADR-0016: the operator is told, so they can add a manual one-off at a
        // real time. A silent gap in the calendar is the thing being avoided.
        $rule = dstRule('03:30', '2026-03-28', '2026-03-30');

        app(GenerateDepartures::class)($rule, Carbon::parse('2026-03-01', 'Europe/Athens'));

        $issues = DepartureReconciler::forRule($rule->refresh(), Carbon::parse('2026-03-01'));

        expect(array_column($issues, 'kind'))->toContain(DepartureReconciler::DST_SKIPPED)
            ->and(array_column($issues, 'local_date'))->toContain('2026-03-29');
    });
})->group('fast');

it('takes the first occurrence on the fall-back date and flags it', function (): void {
    dstTenant(function (): void {
        // 25 October 2026: 04:00 becomes 03:00, so 03:30 happens twice.
        // ADR-0016 fixes the tie-break as the earlier instant, at +03:00.
        $rule = dstRule('03:30', '2026-10-24', '2026-10-26');

        $result = app(GenerateDepartures::class)($rule, Carbon::parse('2026-10-01', 'Europe/Athens'));

        $departure = Departure::query()->where('local_date', '2026-10-25')->firstOrFail();

        expect($result->created)->toBe(3)
            ->and($departure->dst_ambiguous)->toBeTrue()
            ->and($departure->starts_at_utc->toDateTimeString())->toBe('2026-10-25 00:30:00')
            ->and($departure->local_time)->toBe('03:30:00');
    });
})->group('fast');

it('leaves an ordinary time on a transition date alone', function (): void {
    dstTenant(function (): void {
        // The transition days are ordinary for every departure outside the
        // 03:00–03:59 window, and a job that special-cased the whole day would
        // be wrong about all of them.
        $rule = dstRule('09:00', '2026-03-28', '2026-03-30');

        $result = app(GenerateDepartures::class)($rule, Carbon::parse('2026-03-01', 'Europe/Athens'));

        expect($result->created)->toBe(3)
            ->and($result->dstSkippedDates)->toBe([])
            // 29 March is after the change, so 09:00 local is 06:00 UTC.
            ->and(Departure::query()->where('local_date', '2026-03-29')->firstOrFail()
                ->starts_at_utc->toDateTimeString())
            ->toBe('2026-03-29 06:00:00');
    });
})->group('fast');

it('keeps a trip across a transition correct in elapsed time', function (): void {
    dstTenant(function (): void {
        // AVL-17: four hours of sea time is four hours whatever the clock does,
        // which is what the crew and the vessel schedule care about.
        $rule = dstRule('01:00', '2026-03-29', '2026-03-29');

        app(GenerateDepartures::class)($rule, Carbon::parse('2026-03-01', 'Europe/Athens'));

        $departure = Departure::query()->firstOrFail();

        expect($departure->ends_at_utc->getTimestamp() - $departure->starts_at_utc->getTimestamp())
            ->toBe(240 * 60);
    });
})->group('fast');
