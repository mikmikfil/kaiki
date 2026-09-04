<?php

declare(strict_types=1);

use App\Domain\Pricing\Actions\RecomputePriceFrom;
use App\Enums\BookingMode;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\Tenant;
use App\Support\Tenancy;

/*
|--------------------------------------------------------------------------
| `products.price_from_cents` — data-model §1.9
|--------------------------------------------------------------------------
|
| The "from €X" on a list card, derived and stored so the widget mount does not
| fan out across seasons, plans and bands for every product on the page.
|
| The interesting assertions are the ones about **null**. A `quote` product and
| a product with no plan both have no price, and both must store null so the
| list renders nothing — writing 0 for "unknown" is how a free trip reaches a
| public page.
|
*/

function priceFromTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

/** A per-seat product with an adult base band. */
function pricedProduct(): Product
{
    $product = Product::factory()->create();

    AgeBand::factory()->create(['product_id' => $product->getKey()]);

    return $product;
}

it('takes the cheapest base-band price across active plans', function (): void {
    priceFromTenant(function (): void {
        $product = pricedProduct();
        $base = $product->ageBands()->firstOrFail();

        $cheap = RatePlan::factory()->create(['product_id' => $product->getKey()]);
        $cheap->prices()->create(['age_band_id' => $base->getKey(), 'price_cents' => 4000]);

        $dear = RatePlan::factory()->create(['product_id' => $product->getKey(), 'season_id' => null]);
        $dear->forceFill(['season_id' => null])->saveQuietly();
        $dear->prices()->create(['age_band_id' => $base->getKey(), 'price_cents' => 9000]);

        expect($product->refresh()->price_from_cents)->toBe(4000);
    });
})->group('fast');

it('recomputes when a price row changes', function (): void {
    priceFromTenant(function (): void {
        // `SaveRatePlan` rewrites the price rows without touching the plan, so
        // watching the plan alone would leave yesterday's figure on the card.
        $product = pricedProduct();
        $base = $product->ageBands()->firstOrFail();

        $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
        $price = $plan->prices()->create(['age_band_id' => $base->getKey(), 'price_cents' => 5000]);

        expect($product->refresh()->price_from_cents)->toBe(5000);

        $price->update(['price_cents' => 3000]);

        expect($product->refresh()->price_from_cents)->toBe(3000);
    });
})->group('fast');

it('recomputes when a plan is switched off', function (): void {
    priceFromTenant(function (): void {
        $product = pricedProduct();
        $base = $product->ageBands()->firstOrFail();

        $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
        $plan->prices()->create(['age_band_id' => $base->getKey(), 'price_cents' => 5000]);

        expect($product->refresh()->price_from_cents)->toBe(5000);

        $plan->update(['is_active' => false]);

        expect($product->refresh()->price_from_cents)->toBeNull();
    });
})->group('fast');

it('recomputes when the base band moves', function (): void {
    priceFromTenant(function (): void {
        // An operator moving the base flag from adult to child changes the
        // figure on every card without touching a single price — exactly the
        // change nobody thinks to recompute after.
        $product = pricedProduct();
        $adult = $product->ageBands()->firstOrFail();
        $child = AgeBand::factory()->child()->create(['product_id' => $product->getKey()]);

        $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
        $plan->prices()->create(['age_band_id' => $adult->getKey(), 'price_cents' => 5000]);
        $plan->prices()->create(['age_band_id' => $child->getKey(), 'price_cents' => 2500]);

        expect($product->refresh()->price_from_cents)->toBe(5000);

        $adult->update(['is_base' => false]);
        $child->update(['is_base' => true]);

        expect($product->refresh()->price_from_cents)->toBe(2500);
    });
})->group('fast');

it('ignores a band that takes no seat', function (): void {
    priceFromTenant(function (): void {
        // §1.9 says "capacity-counting". An infant priced at zero is a lap, not
        // a "from" price.
        $product = Product::factory()->create();
        $infant = AgeBand::factory()->infant()->create(['product_id' => $product->getKey()]);
        $infant->update(['is_base' => true]);

        $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
        $plan->prices()->create(['age_band_id' => $infant->getKey(), 'price_cents' => 0]);

        expect($product->refresh()->price_from_cents)->toBeNull();
    });
})->group('fast');

it('uses the whole-boat price for a charter', function (): void {
    priceFromTenant(function (): void {
        // §1.9 is written for the per-seat case. A charter has no bands, and
        // the honest "from" figure is what a guest comparing charters reads.
        $product = Product::factory()->perVessel()->create();

        RatePlan::factory()->perVessel(90000)->create(['product_id' => $product->getKey()]);
        RatePlan::factory()->perVessel(60000)->create(['product_id' => $product->getKey()]);

        expect($product->refresh()->price_from_cents)->toBe(60000);
    });
})->group('fast');

it('stores null for a quote product', function (): void {
    priceFromTenant(function (): void {
        // A charter agreed by phone has no price by design, and CAT-5 says it
        // never produces a guest-facing one.
        $product = Product::factory()->create(['mode' => BookingMode::Quote]);

        RatePlan::factory()->create(['product_id' => $product->getKey()]);

        expect($product->refresh()->price_from_cents)->toBeNull();
    });
})->group('fast');

it('stores null for a product with no plan at all', function (): void {
    priceFromTenant(function (): void {
        $product = pricedProduct();

        expect(app(RecomputePriceFrom::class)($product))->toBeNull()
            ->and($product->refresh()->price_from_cents)->toBeNull();
    });
})->group('fast');

it('does not move when a season changes', function (): void {
    priceFromTenant(function (): void {
        // Deliberate, and worth pinning. §1.9 defines the figure across active
        // **rate plans**, not across plans resolvable today — so "from €X" is
        // the floor across the operator's whole year, which is what a listing
        // card means by "from". Were it season-aware it would have to change at
        // midnight on a calendar boundary with nothing to trigger it.
        $product = pricedProduct();
        $base = $product->ageBands()->firstOrFail();

        $season = Season::factory()->withRange('2026-06-01', '2026-09-15')->create();
        $plan = RatePlan::factory()->forSeason($season)->create(['product_id' => $product->getKey()]);
        $plan->prices()->create(['age_band_id' => $base->getKey(), 'price_cents' => 7000]);

        expect($product->refresh()->price_from_cents)->toBe(7000);

        $season->update(['is_active' => false]);

        expect($product->refresh()->price_from_cents)->toBe(7000);
    });
})->group('fast');
