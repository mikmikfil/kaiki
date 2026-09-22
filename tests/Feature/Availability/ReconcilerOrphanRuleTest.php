<?php

declare(strict_types=1);

use App\Domain\Availability\Support\DepartureReconciler;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| A deleted trip must not take the panel down with it (2026-09-22)
|--------------------------------------------------------------------------
|
| Reported from a live panel: *«είμαι εδώ και είναι σπασμένο»* — every page of
| `/app` returning 500 for one operator. The cause was one archived trip.
|
| Deleting a trip is a **soft** delete and its schedule rules stay behind, so
| `$rule->product` is null while `$rule->product_id` still points at a row. The
| reconciler read `$rule->product->title`, which under Laravel's error handler
| is an exception rather than a warning — and the reconciler runs inside a
| widget the panel **layout** renders, so the failure was not confined to the
| list that asked the question. The dashboard, the trips, the bookings: all of
| them.
|
| The rule is: this reconciler answers about trips that exist. A rule whose trip
| is gone is skipped, and comes back on its own if the trip is restored.
|
*/

function reconcilerTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);
}

it('skips an active rule whose trip has been deleted', function (): void {
    reconcilerTenant(function (): void {
        $vessel = Vessel::factory()->create();
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey()]);

        ScheduleRule::factory()->create([
            'product_id' => $product->getKey(),
            'is_active' => true,
        ]);

        // The operator archives the trip. The rule is untouched, as it is on a
        // real panel — nothing cascades a soft delete.
        $product->delete();

        expect(ScheduleRule::query()->where('is_active', true)->count())->toBe(1)
            ->and(DepartureReconciler::all(Carbon::parse('2026-07-01')))->toBeEmpty();
    });
})->group('fast');

it('answers again for a trip that is restored', function (): void {
    reconcilerTenant(function (): void {
        $vessel = Vessel::factory()->create();
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey()]);

        ScheduleRule::factory()->create(['product_id' => $product->getKey(), 'is_active' => true]);

        $product->delete();
        $product->restore();

        // Not about the issues themselves — only that the rule is looked at
        // again rather than skipped for ever.
        expect(DepartureReconciler::all(Carbon::parse('2026-07-01')))->toBeInstanceOf(Collection::class);

        $rule = ScheduleRule::query()->sole();

        expect($rule->getRelationValue('product'))->toBeInstanceOf(Product::class);
    });
})->group('fast');

it('names no trip when asked about an orphan rule directly', function (): void {
    // `forRule()` is public, and a caller may hold a rule whose trip was
    // deleted after they read it. It answers rather than throwing.
    reconcilerTenant(function (): void {
        $vessel = Vessel::factory()->create();
        $product = Product::factory()->create(['vessel_id' => $vessel->getKey()]);

        $rule = ScheduleRule::factory()->create(['product_id' => $product->getKey(), 'is_active' => true]);

        $product->delete();
        $rule->unsetRelation('product');

        expect(DepartureReconciler::forRule($rule, Carbon::parse('2026-07-01')))->toBeArray();
    });
})->group('fast');
