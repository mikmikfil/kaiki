<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\GenerateDepartures;
use App\Domain\Availability\Support\WeekdayMask;
use App\Models\Departure;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Idempotency is the database's job — data-model §2.4
|--------------------------------------------------------------------------
|
| The Action skips instants it can see, which is an optimisation. The guarantee
| is `departures_tenant_prod_start_uq`, and this file exists to prove that the
| index — not the PHP — is what stops a duplicate.
|
| That distinction matters because two workers can run the job for the same rule
| at the same time: both read an empty window, both decide to insert, and only
| an index can arbitrate. The genuinely concurrent case needs row locks and is
| MySQL-only in CI; what runs everywhere is the assertion that the index refuses
| the second write.
|
*/

function idempotencyTenant(callable $callback): mixed
{
    Queue::fake();

    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

function idempotencyRule(): ScheduleRule
{
    $vessel = Vessel::factory()->create(['capacity_max' => 30]);
    $product = Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'max_pax' => 12,
        'duration_minutes' => 240,
    ]);

    return ScheduleRule::factory()->create([
        'product_id' => $product->getKey(),
        'weekday_mask' => WeekdayMask::fromDays([1]),
        'start_time' => '09:00',
        'valid_from' => '2026-06-01',
        'valid_until' => '2026-06-30',
    ]);
}

it('lets the index, not the PHP, refuse a duplicate', function (): void {
    idempotencyTenant(function (): void {
        // Written straight past the Action, which is what a second worker
        // effectively does when both read the window before either writes.
        $rule = idempotencyRule();
        app(GenerateDepartures::class)($rule, Carbon::parse('2026-05-01', 'Europe/Athens'));

        $existing = Departure::query()->orderBy('local_date')->firstOrFail();

        Departure::query()->create([
            ...$existing->only([
                'product_id', 'vessel_id', 'schedule_rule_id', 'local_date', 'local_time',
                'starts_at_utc', 'ends_at_utc', 'capacity', 'min_pax',
            ]),
        ]);
    });
})->throws(QueryException::class)->group('fast');

it('runs three times and creates the same five departures', function (): void {
    idempotencyTenant(function (): void {
        $rule = idempotencyRule();

        foreach (range(1, 3) as $ignored) {
            app(GenerateDepartures::class)($rule->refresh(), Carbon::parse('2026-05-01', 'Europe/Athens'));
        }

        expect(Departure::query()->count())->toBe(5);
    });
})->group('fast');

it('creates one departure per instant even when two rules overlap', function (): void {
    idempotencyTenant(function (): void {
        // §2.3: "Overlapping rules on the same product/time produce one
        // departure, not two." The unique index is on (tenant, product,
        // instant), and neither rule owns the instant more than the other.
        $rule = idempotencyRule();

        $second = ScheduleRule::factory()->create([
            'product_id' => $rule->product_id,
            'weekday_mask' => WeekdayMask::DAILY,
            'start_time' => '09:00',
            'valid_from' => '2026-06-01',
            'valid_until' => '2026-06-07',
        ]);

        app(GenerateDepartures::class)($rule, Carbon::parse('2026-05-01', 'Europe/Athens'));
        $overlapping = app(GenerateDepartures::class)($second, Carbon::parse('2026-05-01', 'Europe/Athens'));

        // Six of the seven days are new; 1 June already exists from the Monday
        // rule and is skipped rather than duplicated.
        expect($overlapping->created)->toBe(6)
            ->and($overlapping->skipped)->toBe(1)
            ->and(Departure::query()->where('local_date', '2026-06-01')->count())->toBe(1);
    });
})->group('fast');
