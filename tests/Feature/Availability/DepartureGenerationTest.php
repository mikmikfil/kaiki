<?php

declare(strict_types=1);

use App\Data\Availability\GenerationResultData;
use App\Domain\Availability\Actions\GenerateDepartures;
use App\Domain\Availability\Support\WeekdayMask;
use App\Models\Departure;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Departure generation — ADR-0009 Option A, spec AVL-52 to AVL-55
|--------------------------------------------------------------------------
|
| **Additive only.** The job creates rows and does nothing else. That is what
| makes it safe to re-run every night, and it is what stops a rule edit
| cancelling a departure somebody paid for.
|
| Idempotency rests on `departures_tenant_prod_start_uq`, not on a second rule
| in PHP. The Action skips instants it can see, which is an optimisation; the
| guarantee is the index, and it is on the UTC instant so the October DST repeat
| cannot collide.
|
*/

function generationTenant(callable $callback): mixed
{
    // The queue is faked throughout: the observer dispatches on every rule save
    // (AVL-54), and a test that let it run would be testing the observer rather
    // than the Action it means to.
    Queue::fake();

    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

/**
 * A per-seat product with a boat, and a rule over a known window.
 *
 * @param  array<string, mixed>  $overrides
 */
function ruleFor(array $overrides = []): ScheduleRule
{
    $vessel = Vessel::factory()->create(['capacity_max' => 30]);
    $product = Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'max_pax' => 12,
        'min_pax' => 4,
        'duration_minutes' => 240,
    ]);

    return ScheduleRule::factory()->create(array_merge([
        'product_id' => $product->getKey(),
        'weekday_mask' => WeekdayMask::fromDays([1]),
        'start_time' => '09:00',
        'valid_from' => '2026-06-01',
        'valid_until' => '2026-06-30',
    ], $overrides));
}

function generate(ScheduleRule $rule, string $today = '2026-05-01'): GenerationResultData
{
    return app(GenerateDepartures::class)($rule, Carbon::parse($today, 'Europe/Athens'));
}

it('creates one departure per matching local date', function (): void {
    generationTenant(function (): void {
        // Mondays in June 2026: the 1st, 8th, 15th, 22nd and 29th.
        $result = generate(ruleFor());

        expect($result->created)->toBe(5)
            ->and(Departure::query()->count())->toBe(5)
            ->and(Departure::query()->orderBy('local_date')->pluck('local_date')
                ->map(static fn (Carbon $d): string => $d->toDateString())->all())
            ->toBe(['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22', '2026-06-29']);
    });
})->group('fast');

it('snapshots capacity, minimum, vessel and the time triple', function (): void {
    generationTenant(function (): void {
        // §1.9 and AVL-55: copies taken now, so a later edit to the product or
        // the rule cannot silently re-guarantee a departure people have booked.
        $rule = ruleFor();

        generate($rule);

        $departure = Departure::query()->orderBy('local_date')->firstOrFail();

        expect($departure->capacity)->toBe(12)
            ->and($departure->min_pax)->toBe(4)
            ->and($departure->vessel_id)->toBe($rule->product->vessel_id)
            ->and($departure->schedule_rule_id)->toBe($rule->getKey())
            ->and($departure->local_time)->toBe('09:00:00')
            // June is EEST (+3), and 240 minutes of absolute elapsed time.
            ->and($departure->starts_at_utc->toDateTimeString())->toBe('2026-06-01 06:00:00')
            ->and($departure->ends_at_utc->toDateTimeString())->toBe('2026-06-01 10:00:00');
    });
})->group('fast');

it('creates nothing and changes nothing on a second run', function (): void {
    generationTenant(function (): void {
        // AVL-54. The assertion on `updated_at` is the one that matters: a
        // version that re-saved every row would pass a row count and quietly
        // rewrite the whole horizon every night.
        $rule = ruleFor();

        generate($rule);

        $before = Departure::query()->orderBy('id')->pluck('updated_at')->all();

        Carbon::setTestNow(Carbon::parse('2026-05-01 12:00:00'));
        $second = generate($rule);
        Carbon::setTestNow();

        expect($second->created)->toBe(0)
            ->and($second->skipped)->toBe(5)
            ->and(Departure::query()->count())->toBe(5)
            ->and(Departure::query()->orderBy('id')->pluck('updated_at')->all())->toEqual($before);
    });
})->group('fast');

it('adds only the newly matching dates when a rule gains a weekday', function (): void {
    generationTenant(function (): void {
        $rule = ruleFor();

        generate($rule);

        // Mondays and Wednesdays now.
        $rule->forceFill(['weekday_mask' => WeekdayMask::fromDays([1, 3])])->saveQuietly();

        $second = generate($rule->refresh());

        expect($second->created)->toBe(4)
            ->and($second->skipped)->toBe(5)
            ->and(Departure::query()->count())->toBe(9);
    });
})->group('fast');

it('never creates a departure in the past', function (): void {
    generationTenant(function (): void {
        // A job run after a gap must not manufacture departures for dates that
        // have already sailed, or a guest sees yesterday's trip on sale.
        $rule = ruleFor();

        $result = generate($rule, today: '2026-06-16');

        expect($result->created)->toBe(2)
            ->and(Departure::query()->min('local_date'))->toBe('2026-06-22');
    });
})->group('fast');

it('stops at the horizon for an open-ended rule', function (): void {
    generationTenant(function (): void {
        // "Open-ended" means the operator set no end date, not that the job
        // generates forever (§2.3, ADR-0009).
        config()->set('kaiki.departures.horizon_days', 30);

        $rule = ruleFor(['valid_until' => null]);

        generate($rule, today: '2026-06-01');

        expect(Departure::query()->count())->toBe(5)
            ->and(Departure::query()->max('local_date'))->toBe('2026-06-29');
    });
})->group('fast');

it('advances the watermark to the last date it walked', function (): void {
    generationTenant(function (): void {
        // §2.3: so a run that failed part way resumes rather than restarting.
        $rule = ruleFor();

        generate($rule);

        // The last date it actually generated, not the end of the window:
        // the criterion is "advanced only for the dates actually generated".
        expect($rule->refresh()->last_generated_on?->toDateString())->toBe('2026-06-29');
    });
})->group('fast');

it('generates nothing for an inactive rule', function (): void {
    generationTenant(function (): void {
        expect(generate(ruleFor(['is_active' => false]))->created)->toBe(0)
            ->and(Departure::query()->count())->toBe(0);
    });
})->group('fast');

it('generates nothing for a rule whose product has no boat', function (): void {
    generationTenant(function (): void {
        // `departures.vessel_id` is NOT NULL because a sailing without a vessel
        // is not a sailing. The operator hears about it on the reconciliation
        // page rather than through a failed job.
        $product = Product::factory()->create(['vessel_id' => null]);
        $rule = ScheduleRule::factory()->create(['product_id' => $product->getKey()]);

        expect(generate($rule)->created)->toBe(0)
            ->and(Departure::query()->count())->toBe(0);
    });
})->group('fast');

it('generates a 400-day horizon in a bounded number of queries', function (): void {
    generationTenant(function (): void {
        // NFR-6. A per-date `firstOrCreate` would be four hundred round trips
        // for a daily rule, which is the shape that makes a nightly job time
        // out at the end of a season rather than at the start.
        $rule = ruleFor(['weekday_mask' => WeekdayMask::DAILY, 'valid_until' => null]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        generate($rule, today: '2026-06-01');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect(Departure::query()->count())->toBe(401)
            // The select, three chunked inserts, the capacity sync and the
            // watermark — plus what the relations cost. Well under one per day.
            ->and(count($queries))->toBeLessThan(15);
    });
})->group('fast');
