<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\GenerateDepartures;
use App\Domain\Availability\Support\DepartureReconciler;
use App\Domain\Availability\Support\WeekdayMask;
use App\Jobs\GenerateDeparturesForRule;
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
| A rule change never touches a sold departure — ADR-0009
|--------------------------------------------------------------------------
|
| This is what Option A buys, and the reason it is worth the extra page of UI:
| *"The operator is never surprised by a rule edit silently cancelling a
| departure someone paid for."*
|
| So the job is additive, the divergence is listed, and the operator decides.
| Without the list, "additive only" would just be a silent inconsistency.
|
*/

function safetyTenant(callable $callback): mixed
{
    Queue::fake();

    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

function safetyRule(): ScheduleRule
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

function runGeneration(ScheduleRule $rule, string $today = '2026-05-01'): void
{
    app(GenerateDepartures::class)($rule, Carbon::parse($today, 'Europe/Athens'));
}

it('leaves a sold departure standing when its rule stops matching the date', function (): void {
    safetyTenant(function (): void {
        $rule = safetyRule();
        runGeneration($rule);

        $sold = Departure::query()->orderBy('local_date')->firstOrFail();
        $sold->forceFill(['seats_sold' => 3])->saveQuietly();

        // The operator moves the trip to Tuesdays.
        $rule->forceFill(['weekday_mask' => WeekdayMask::fromDays([2])])->saveQuietly();

        runGeneration($rule->refresh());

        expect(Departure::query()->whereKey($sold->getKey())->exists())->toBeTrue()
            ->and($sold->refresh()->seats_sold)->toBe(3)
            ->and($sold->status->isSellable())->toBeTrue();
    });
})->group('fast');

it('lists that divergence for the operator rather than applying it', function (): void {
    safetyTenant(function (): void {
        $rule = safetyRule();
        runGeneration($rule);

        Departure::query()->orderBy('local_date')->firstOrFail()
            ->forceFill(['seats_sold' => 3])->saveQuietly();

        $rule->forceFill(['weekday_mask' => WeekdayMask::fromDays([2])])->saveQuietly();
        runGeneration($rule->refresh());

        $issues = DepartureReconciler::forRule($rule->refresh(), Carbon::parse('2026-05-01'));
        $kinds = array_column($issues, 'kind');

        expect($kinds)->toContain(DepartureReconciler::ORPHANED);
    });
})->group('fast');

it('brings an unsold departure into line with a changed capacity', function (): void {
    safetyTenant(function (): void {
        // ADR-0009 draws the line here: nothing is sold, so nobody loses a seat.
        $rule = safetyRule();
        runGeneration($rule);

        $rule->forceFill(['capacity_override' => 8])->saveQuietly();
        runGeneration($rule->refresh());

        expect(Departure::query()->pluck('capacity')->unique()->all())->toBe([8]);
    });
})->group('fast');

it('leaves a sold departure capacity alone and lists it instead', function (): void {
    safetyTenant(function (): void {
        // Lowering the capacity of a departure somebody booked onto is how a
        // guest loses a seat they paid for.
        $rule = safetyRule();
        runGeneration($rule);

        $sold = Departure::query()->orderBy('local_date')->firstOrFail();
        $sold->forceFill(['seats_sold' => 10])->saveQuietly();

        $rule->forceFill(['capacity_override' => 8])->saveQuietly();
        runGeneration($rule->refresh());

        expect($sold->refresh()->capacity)->toBe(12);

        $kinds = array_column(
            DepartureReconciler::forRule($rule->refresh(), Carbon::parse('2026-05-01')),
            'kind',
        );

        expect($kinds)->toContain(DepartureReconciler::CAPACITY_DRIFT);
    });
})->group('fast');

it('reports nothing once the rule and its departures agree', function (): void {
    safetyTenant(function (): void {
        // A derived list cannot be wrong about the present: fixing the rule
        // makes the entry disappear with nothing having to delete it.
        $rule = safetyRule();
        runGeneration($rule);

        expect(DepartureReconciler::forRule($rule->refresh(), Carbon::parse('2026-05-01')))->toBe([]);
    });
})->group('fast');

it('dispatches generation when a rule is saved, and never runs it inline', function (): void {
    // AVL-54 and CNV-10. Four hundred date resolutions and an insert of that
    // many rows is not something an operator pressing Save should wait for, and
    // a request timeout would leave the rule saved and the departures half made.
    Queue::fake();

    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $rule = safetyRule();

        Queue::assertPushed(
            GenerateDeparturesForRule::class,
            static fn (GenerateDeparturesForRule $job): bool => $job->scheduleRuleId === $rule->getKey(),
        );

        // Nothing generated in the request itself.
        expect(Departure::query()->count())->toBe(0);
    });
})->group('fast');

it('does not dispatch for an inactive rule', function (): void {
    Queue::fake();

    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        ScheduleRule::factory()->inactive()->create();

        Queue::assertNotPushed(GenerateDeparturesForRule::class);
    });
})->group('fast');
