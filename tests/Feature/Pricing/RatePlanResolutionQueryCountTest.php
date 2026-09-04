<?php

declare(strict_types=1);

use App\Domain\Pricing\Support\RatePlanResolver;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Resolution is bounded, whatever the range — NFR-6, NFR-7
|--------------------------------------------------------------------------
|
| The resolver is called once per date in a 62-day availability response, and
| again for every product in a list. A version that queried per date would pass
| every correctness test above and turn the hottest read path in the product
| into an N+1 that only shows up against a real calendar.
|
| So this asserts a **count**, not a duration: a benchmark is flaky and a query
| count is exact. Three for the load — plans, seasons, and the seasons' ranges —
| and then **zero**, however many dates follow. The second number is the one
| carrying the requirement: a query inside the loop would still pass every
| correctness test in the file next to this one.
|
*/

it('loads everything a range needs in three queries', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $product = Product::factory()->create();

        $summer = Season::factory()->priority(10)->withRange('2026-06-01', '2026-09-15')->create();
        $august = Season::factory()->priority(50)->withRange('2026-08-01', '2026-08-31')->create();

        RatePlan::factory()->forSeason($summer)->create(['product_id' => $product->getKey()]);
        RatePlan::factory()->forSeason($august)->create(['product_id' => $product->getKey()]);
        RatePlan::factory()->create(['product_id' => $product->getKey()]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        ['plans' => $plans, 'seasons' => $seasons] = RatePlanResolver::load($product);

        $range = RatePlanResolver::resolveRange(
            $plans,
            $seasons,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-30'),
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($range)->toHaveCount(30)
            // The plans, the seasons, and the seasons' ranges eager-loaded.
            ->and($queries)->toHaveCount(3);
    });
})->group('fast');

it('costs no more for sixty dates than for one', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $product = Product::factory()->create();
        $summer = Season::factory()->withRange('2026-06-01', '2026-09-15')->create();

        RatePlan::factory()->forSeason($summer)->create(['product_id' => $product->getKey()]);

        ['plans' => $plans, 'seasons' => $seasons] = RatePlanResolver::load($product);

        DB::enableQueryLog();
        DB::flushQueryLog();

        RatePlanResolver::resolveRange(
            $plans,
            $seasons,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-07-30'),
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Zero. Everything after `load()` is PHP, which is the entire reason
        // the resolver takes collections instead of a product.
        expect($queries)->toHaveCount(0);
    });
})->group('fast');
