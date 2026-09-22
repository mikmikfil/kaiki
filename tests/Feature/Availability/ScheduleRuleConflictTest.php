<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\GenerateDepartures;
use App\Domain\Availability\Support\ScheduleRuleConflictFinder;
use App\Domain\Availability\Support\WeekdayMask;
use App\Domain\Catalog\Actions\SaveScheduleRule;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The boat is already out — a warning, never a refusal (AVL-11)
|--------------------------------------------------------------------------
|
| Product owner, 2026-09-22: *«υπάρχει έλεγχος αν φτιάξω νέα εκδρομή και το
| καράβι έχει ήδη δεσμευτεί σε άλλη εκδρομή για τις ημέρες/ώρες που θα βάλω;»*
| There was not. A manual departure had been checked since M2; a schedule rule
| — which is how a trip gets its days — never looked at the boat's calendar.
|
| AVL-11 permits two trips on one boat at the same hour and lets the bookings
| decide, so the answer is to **tell** the operator, not to stop them. What is
| asserted here is that the question gets a true answer: the clash is found,
| the trip's own departures are not mistaken for one, and a boat that is free
| reports nothing.
|
*/

function ruleConflictTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

/** A per-seat trip on `$vessel` with a rule on `$days` at `$time`, departures generated. */
function ruleTripSailing(Vessel $vessel, array $days, string $time, int $minutes = 180): ScheduleRule
{
    $product = Product::factory()->create([
        'vessel_id' => $vessel->getKey(),
        'duration_minutes' => $minutes,
    ]);

    $rule = app(SaveScheduleRule::class)(new ScheduleRule, $product, [
        'weekday_mask' => WeekdayMask::fromDays($days),
        'start_time' => $time,
        'valid_from' => '2026-07-01',
        'valid_until' => '2026-09-30',
        'is_active' => true,
    ]);

    app(GenerateDepartures::class)($rule, Carbon::parse('2026-07-01'));

    return $rule->refresh();
}

it('finds the other trip the boat is already committed to', function (): void {
    ruleConflictTenant(function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 20]);

        ruleTripSailing($vessel, [2], '10:00');
        $second = ruleTripSailing($vessel, [2], '11:00');

        // 10:00 for three hours and 11:00 for three hours on the same Tuesday,
        // on one boat.
        $conflicts = ScheduleRuleConflictFinder::forRule($second, Carbon::parse('2026-07-01'));

        expect($conflicts)->not->toBeEmpty()
            ->and($conflicts->first()->local_time)->toStartWith('10:00');
    });
})->group('fast');

it('says nothing when the boat is free', function (): void {
    ruleConflictTenant(function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 20]);

        // Tuesday morning and Thursday morning never meet.
        ruleTripSailing($vessel, [2], '10:00');
        $second = ruleTripSailing($vessel, [4], '10:00');

        expect(ScheduleRuleConflictFinder::forRule($second, Carbon::parse('2026-07-01')))->toBeEmpty();
    });
})->group('fast');

it('does not report a trip against its own departures', function (): void {
    ruleConflictTenant(function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 20]);

        // One trip, its own rule, its own generated departures. A rule never
        // conflicts with itself (AVL-9) — and by the time this is asked the
        // departures exist, which is exactly when the naive check fires.
        $only = ruleTripSailing($vessel, [2], '10:00');

        expect(ScheduleRuleConflictFinder::forRule($only, Carbon::parse('2026-07-01')))->toBeEmpty();
    });
})->group('fast');

it('leaves another operator boat alone', function (): void {
    $theirs = Tenancy::forTenant(
        Tenant::factory()->create(['timezone' => 'Europe/Athens']),
        function (): Vessel {
            $vessel = Vessel::factory()->create(['capacity_max' => 20]);
            ruleTripSailing($vessel, [2], '10:00');

            return $vessel;
        },
    );

    ruleConflictTenant(function () use ($theirs): void {
        $mine = Vessel::factory()->create(['capacity_max' => 20]);
        $rule = ruleTripSailing($mine, [2], '10:00');

        expect($theirs->getKey())->not->toBe($mine->getKey())
            ->and(ScheduleRuleConflictFinder::forRule($rule, Carbon::parse('2026-07-01')))->toBeEmpty();
    });
})->group('fast');

it('reports nothing for a rule whose trip has been deleted', function (): void {
    ruleConflictTenant(function (): void {
        $vessel = Vessel::factory()->create(['capacity_max' => 20]);
        $rule = ruleTripSailing($vessel, [2], '10:00');

        $rule->product->delete();
        $rule->unsetRelation('product');

        expect(ScheduleRuleConflictFinder::forRule($rule, Carbon::parse('2026-07-01')))->toBeEmpty();
    });
})->group('fast');
