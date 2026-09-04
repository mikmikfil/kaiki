<?php

declare(strict_types=1);

use App\Domain\Availability\Support\WeekdayMask;
use App\Enums\Role;
use App\Filament\App\Pages\DepartureReconciliation;
use App\Jobs\GenerateDeparturesForRule;
use App\Jobs\GenerateDeparturesNightly;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec AVL-54, NFR-10, NFR-11, ADR-0009.
 *
 * The nightly sweep dispatches one job per rule rather than doing the work
 * itself. A single job that generated everything would grow with the platform
 * and eventually blow the 120-second budget — and when it did it would fail as
 * a whole, losing the tenants it had not reached. Fanned out, a slow tenant
 * delays only itself.
 */

it('registers the nightly generation on the scheduler', function (): void {
    // NFR-11 asks that a missed run can raise an alert, which starts with the
    // run existing. A scheduled job nobody registered is the failure that looks
    // exactly like everything working.
    $events = collect(Schedule::events())
        ->filter(static fn ($event): bool => str_contains((string) $event->description, 'departures:generate-nightly'));

    expect($events)->toHaveCount(1)
        // 03:15 in the tenant timezone: after both DST transitions (03:00 and
        // 04:00 local) have settled, and off the hour every other scheduled job
        // in the world picks. The timezone is on the event rather than baked
        // into the expression, so a platform default change moves it.
        ->and($events->first()?->expression)->toBe('15 3 * * *')
        ->and($events->first()?->timezone)->toBe('Europe/Athens');
})->group('fast');

it('dispatches one job per active rule, across tenants', function (): void {
    Queue::fake();

    foreach (range(1, 2) as $ignored) {
        Tenancy::forTenant(Tenant::factory()->create(), function (): void {
            $vessel = Vessel::factory()->create();
            $product = Product::factory()->create(['vessel_id' => $vessel->getKey()]);

            ScheduleRule::factory()->create(['product_id' => $product->getKey()]);
            ScheduleRule::factory()->inactive()->create(['product_id' => $product->getKey()]);
        });
    }

    // The observer already dispatched for each rule as it was created
    // (AVL-54), so what is measured here is the sweep's own contribution.
    $counted = 0;
    Queue::assertPushed(GenerateDeparturesForRule::class, function () use (&$counted): bool {
        $counted++;

        return true;
    });

    $before = $counted;

    (new GenerateDeparturesNightly)->handle();

    // Two tenants, one active rule each. An inactive rule generates nothing,
    // and dispatching a job to discover that is a round trip for a question
    // already answered.
    $after = 0;
    Queue::assertPushed(GenerateDeparturesForRule::class, function () use (&$after): bool {
        $after++;

        return true;
    });

    expect($after - $before)->toBe(2);
})->group('fast');

it('records its completion so a missed run can be noticed', function (): void {
    Queue::fake();
    Cache::forget(GenerateDeparturesNightly::COMPLETED_AT_KEY);

    (new GenerateDeparturesNightly)->handle();

    expect(Cache::get(GenerateDeparturesNightly::COMPLETED_AT_KEY))->toBeString();
})->group('fast');

it('carries ids rather than models, so a worker can open the tenant itself', function (): void {
    // A serialised Eloquent model reloads through the global tenant scope,
    // which is not initialised in a worker process — the reload finds nothing
    // and the job fails with a message about a missing model rather than about
    // tenancy.
    $job = new GenerateDeparturesForRule(7, 42);

    expect($job->tenantId)->toBe(7)
        ->and($job->scheduleRuleId)->toBe(42)
        ->and($job->uniqueId())->toBe('7:42');
})->group('fast');

it('shows the reconciliation page to an owner and hides it from crew', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $crew = OperatorUser::withRole(Role::Crew);

    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));
    actingAs($owner);
    expect(DepartureReconciliation::canAccess())->toBeTrue();

    tenancy()->initialize(Tenant::query()->findOrFail($crew->tenant_id));
    actingAs($crew);
    expect(DepartureReconciliation::canAccess())->toBeFalse();
})->group('fast');

it('keeps the reconciliation page out of the menu when nothing is wrong', function (): void {
    // A permanently visible "no problems" page is furniture, and an operator
    // who learns to ignore a menu item will ignore it on the day it matters.
    Queue::fake();

    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    tenancy()->initialize($tenant);
    actingAs($owner);

    expect(DepartureReconciliation::shouldRegisterNavigation())->toBeFalse();

    Tenancy::forTenant($tenant, function (): void {
        $vessel = Vessel::factory()->create();
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey()]);

        // A rule at a time that does not exist on the spring-forward date.
        ScheduleRule::factory()->create([
            'product_id' => $product->getKey(),
            'weekday_mask' => WeekdayMask::DAILY,
            'start_time' => '03:30',
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addYears(2)->toDateString(),
        ]);
    });

    tenancy()->initialize($tenant);

    expect(DepartureReconciliation::shouldRegisterNavigation())->toBeTrue();
})->group('fast');
