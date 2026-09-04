<?php

declare(strict_types=1);

use App\Domain\Pricing\Actions\SaveRatePlan;
use App\Enums\DepositType;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| One default plan per product — data-model §2.3 caveat
|--------------------------------------------------------------------------
|
| `rate_plans_tenant_prod_season_uq` looks like it enforces this and does not.
| **MySQL and SQLite both treat NULLs as distinct in a unique index**, so two
| rows with `season_id` null satisfy the constraint on either engine, and the
| default plan is precisely the row with `season_id` null.
|
| A partial index would express it and is unportable (ENV-12), so the rule lives
| in the Action — and this file exists to prove both halves: that the index
| really does let a duplicate through, and that the Action really does not.
|
| The first half is the one that matters. Without it, someone reads the index
| name, believes the database is holding the line, and deletes the check.
|
*/

function inDefaultPlanTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

function pricedSeatProduct(): Product
{
    $product = Product::factory()->create();

    AgeBand::factory()->create(['product_id' => $product->getKey()]);

    return $product;
}

/** @param array<string, mixed> $overrides */
function saveDefaultPlan(Product $product, array $overrides = []): RatePlan
{
    $base = $product->ageBands()->where('is_base', true)->firstOrFail();

    return app(SaveRatePlan::class)(
        new RatePlan,
        $product,
        array_merge([
            'season_id' => null,
            'deposit_type' => DepositType::None->value,
            'min_lead_time_hours' => 0,
            'is_active' => true,
        ], $overrides),
        [$base->getKey() => 5000],
    );
}

it('refuses a second default plan for the same product', function (): void {
    inDefaultPlanTenant(function (): void {
        $product = pricedSeatProduct();

        saveDefaultPlan($product);
        saveDefaultPlan($product);
    });
})->throws(ValidationException::class)->group('fast');

it('lets the existing default plan be edited without tripping its own check', function (): void {
    inDefaultPlanTenant(function (): void {
        // The check excludes the row being saved. Without that, an operator
        // could create a default plan once and never change it again.
        $product = pricedSeatProduct();
        $base = $product->ageBands()->where('is_base', true)->firstOrFail();

        $plan = saveDefaultPlan($product);

        $plan = app(SaveRatePlan::class)(
            $plan,
            $product,
            ['season_id' => null, 'name' => 'Renamed', 'deposit_type' => DepositType::None->value, 'min_lead_time_hours' => 12, 'is_active' => true],
            [$base->getKey() => 5000],
        );

        expect($plan->name)->toBe('Renamed')
            ->and(RatePlan::query()->where('product_id', $product->getKey())->count())->toBe(1);
    });
})->group('fast');

it('scopes the check to one product', function (): void {
    inDefaultPlanTenant(function (): void {
        saveDefaultPlan(pricedSeatProduct());
        saveDefaultPlan(pricedSeatProduct());

        expect(RatePlan::query()->count())->toBe(2);
    });
})->group('fast');

it('scopes the check to one tenant', function (): void {
    // Two operators may each have a default plan for their own product, and
    // the global scope is what keeps the check from seeing across the fence.
    $counts = [];

    foreach (range(1, 2) as $ignored) {
        $counts[] = inDefaultPlanTenant(function (): int {
            saveDefaultPlan(pricedSeatProduct());

            return RatePlan::query()->count();
        });
    }

    expect($counts)->toBe([1, 1]);
})->group('fast');

it('confirms the unique index does not enforce this, which is why the Action does', function (): void {
    inDefaultPlanTenant(function (): void {
        // Deliberately bypassing the Action. If this ever throws, the engine
        // has started treating NULLs as equal in a unique index — at which
        // point the §2.3 caveat is obsolete and this file should say so.
        $product = pricedSeatProduct();

        $row = [
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->getKey(),
            'season_id' => null,
            'deposit_type' => DepositType::None->value,
            'min_lead_time_hours' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('rate_plans')->insert($row);
        DB::table('rate_plans')->insert($row);

        expect(DB::table('rate_plans')->whereNull('season_id')->count())->toBe(2);
    });
})->group('fast');
