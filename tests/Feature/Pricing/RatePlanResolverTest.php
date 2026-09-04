<?php

declare(strict_types=1);

use App\Data\Pricing\ResolvedRatePlanData;
use App\Domain\Pricing\Support\RatePlanResolver;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Which plan prices a date — spec PRC-3, PRC-4, PRC-5
|--------------------------------------------------------------------------
|
| PRC-5's walk has a step people skip: the winning **season** and the winning
| **plan** are not the same question. A season can be the highest-priority match
| and simply have no plan for this product, and the rule is to keep walking the
| ordered candidates rather than to fail there. Skipping that step gives an
| operator a trip that stops being bookable in August because they wrote an
| August season for a different boat.
|
| "Not sellable" is a value here, never an exception and never a zero. PRC-5 is
| explicit that availability omits the product; a zero would be a free trip on a
| public booking page.
|
*/

function resolverTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

function resolveOn(Product $product, string $date): ResolvedRatePlanData
{
    return RatePlanResolver::forProduct($product, Carbon::parse($date));
}

it('resolves the plan of the only season containing the date', function (): void {
    resolverTenant(function (): void {
        $product = Product::factory()->create();
        $summer = Season::factory()->withRange('2026-06-01', '2026-09-15')->create();
        $plan = RatePlan::factory()->forSeason($summer)->create(['product_id' => $product->getKey()]);

        $resolved = resolveOn($product, '2026-07-04');

        expect($resolved->isSellable())->toBeTrue()
            ->and($resolved->plan?->getKey())->toBe($plan->getKey())
            ->and($resolved->season?->getKey())->toBe($summer->getKey())
            ->and($resolved->usedDefaultPlan())->toBeFalse();
    });
})->group('fast');

it('prefers the higher-priority season when both contain the date', function (): void {
    resolverTenant(function (): void {
        // How "August" sits inside "Summer": the narrower, higher-priority
        // season is the operator's more specific statement.
        $product = Product::factory()->create();

        $summer = Season::factory()->priority(10)->withRange('2026-06-01', '2026-09-15')->create();
        $august = Season::factory()->priority(50)->withRange('2026-08-01', '2026-08-31')->create();

        RatePlan::factory()->forSeason($summer)->create(['product_id' => $product->getKey()]);
        $peak = RatePlan::factory()->forSeason($august)->create(['product_id' => $product->getKey()]);

        expect(resolveOn($product, '2026-08-10')->plan?->getKey())->toBe($peak->getKey())
            // Outside August, the wider season still answers.
            ->and(resolveOn($product, '2026-07-04')->season?->getKey())->toBe($summer->getKey());
    });
})->group('fast');

it('keeps walking when the winning season has no plan for this product', function (): void {
    resolverTenant(function (): void {
        // The step that is easy to miss. August wins the season contest and has
        // a plan for *another* product; this product must fall through to
        // Summer rather than becoming unsellable for the month.
        $product = Product::factory()->create();
        $other = Product::factory()->create();

        $summer = Season::factory()->priority(10)->withRange('2026-06-01', '2026-09-15')->create();
        $august = Season::factory()->priority(50)->withRange('2026-08-01', '2026-08-31')->create();

        RatePlan::factory()->forSeason($august)->create(['product_id' => $other->getKey()]);
        $summerPlan = RatePlan::factory()->forSeason($summer)->create(['product_id' => $product->getKey()]);

        $resolved = resolveOn($product, '2026-08-10');

        expect($resolved->plan?->getKey())->toBe($summerPlan->getKey())
            ->and($resolved->season?->getKey())->toBe($summer->getKey());
    });
})->group('fast');

it('falls back to the product default when no season has a plan', function (): void {
    resolverTenant(function (): void {
        $product = Product::factory()->create();

        Season::factory()->withRange('2026-06-01', '2026-09-15')->create();
        $default = RatePlan::factory()->create(['product_id' => $product->getKey()]);

        $resolved = resolveOn($product, '2026-07-04');

        expect($resolved->plan?->getKey())->toBe($default->getKey())
            ->and($resolved->season)->toBeNull()
            ->and($resolved->usedDefaultPlan())->toBeTrue();
    });
})->group('fast');

it('uses the default plan for a date in no season at all', function (): void {
    resolverTenant(function (): void {
        $product = Product::factory()->create();

        Season::factory()->withRange('2026-06-01', '2026-09-15')->create();
        $default = RatePlan::factory()->create(['product_id' => $product->getKey()]);

        expect(resolveOn($product, '2026-01-15')->plan?->getKey())->toBe($default->getKey());
    });
})->group('fast');

it('reports not sellable when neither a seasonal nor a default plan exists', function (): void {
    resolverTenant(function (): void {
        // PRC-5, and the assertion that matters: a value saying no, not an
        // exception and not a zero.
        $product = Product::factory()->create();

        $resolved = resolveOn($product, '2026-07-04');

        expect($resolved->isSellable())->toBeFalse()
            ->and($resolved->plan)->toBeNull()
            ->and($resolved->season)->toBeNull();
    });
})->group('fast');

it('throws rather than pricing nothing when a caller ignores the answer', function (): void {
    resolverTenant(function (): void {
        resolveOn(Product::factory()->create(), '2026-07-04')->planOrFail();
    });
})->throws(LogicException::class)->group('fast');

it('excludes an inactive plan', function (): void {
    resolverTenant(function (): void {
        // An operator who switched a plan off meant it to stop pricing. Falling
        // through to the default is right; using it anyway is not.
        $product = Product::factory()->create();
        $summer = Season::factory()->withRange('2026-06-01', '2026-09-15')->create();

        RatePlan::factory()->forSeason($summer)->inactive()->create(['product_id' => $product->getKey()]);
        $default = RatePlan::factory()->create(['product_id' => $product->getKey()]);

        expect(resolveOn($product, '2026-07-04')->plan?->getKey())->toBe($default->getKey());
    });
})->group('fast');

it('excludes an inactive season', function (): void {
    resolverTenant(function (): void {
        $product = Product::factory()->create();
        $summer = Season::factory()->inactive()->withRange('2026-06-01', '2026-09-15')->create();

        RatePlan::factory()->forSeason($summer)->create(['product_id' => $product->getKey()]);
        $default = RatePlan::factory()->create(['product_id' => $product->getKey()]);

        expect(resolveOn($product, '2026-07-04')->plan?->getKey())->toBe($default->getKey());
    });
})->group('fast');

it('never lets another product plan price this one', function (): void {
    resolverTenant(function (): void {
        $product = Product::factory()->create();
        $other = Product::factory()->create();

        RatePlan::factory()->create(['product_id' => $other->getKey()]);

        expect(resolveOn($product, '2026-07-04')->isSellable())->toBeFalse();
    });
})->group('fast');

it('resolves a same-priority tie the same way twice', function (): void {
    resolverTenant(function (): void {
        // A tie is prevented at save time by `SaveSeason` — this is for the rows
        // that got in another way: an import, a direct edit. PRC-4: the engine
        // must never depend on database row order.
        $product = Product::factory()->create();

        $wide = Season::factory()->priority(10)->withRange('2026-06-01', '2026-09-15')->create();
        $narrow = Season::factory()->priority(10)->withRange('2026-08-01', '2026-08-31')->create();

        RatePlan::factory()->forSeason($wide)->create(['product_id' => $product->getKey()]);
        $narrowPlan = RatePlan::factory()->forSeason($narrow)->create(['product_id' => $product->getKey()]);

        // Narrowest matching range wins the tie, and says so twice.
        expect(resolveOn($product, '2026-08-10')->plan?->getKey())->toBe($narrowPlan->getKey())
            ->and(resolveOn($product, '2026-08-10')->plan?->getKey())->toBe($narrowPlan->getKey());
    });
})->group('fast');

it('answers every date in a range, including the ones with no plan', function (): void {
    resolverTenant(function (): void {
        // Dropping the unsellable dates would leave the caller unable to tell
        // "not sellable" from "not asked".
        $product = Product::factory()->create();
        $summer = Season::factory()->withRange('2026-06-01', '2026-06-03')->create();

        RatePlan::factory()->forSeason($summer)->create(['product_id' => $product->getKey()]);

        ['plans' => $plans, 'seasons' => $seasons] = RatePlanResolver::load($product);

        $range = RatePlanResolver::resolveRange(
            $plans,
            $seasons,
            Carbon::parse('2026-05-31'),
            Carbon::parse('2026-06-04'),
        );

        expect(array_keys($range))->toBe(['2026-05-31', '2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04'])
            ->and($range['2026-05-31']->isSellable())->toBeFalse()
            ->and($range['2026-06-02']->isSellable())->toBeTrue()
            ->and($range['2026-06-04']->isSellable())->toBeFalse();
    });
})->group('fast');
