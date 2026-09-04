<?php

declare(strict_types=1);

use App\Domain\Pricing\Support\PaxLineBuilder;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| Per-seat lines — spec PRC-6, PRC-7
|--------------------------------------------------------------------------
|
| PRC-6's rounding order is the sort of detail that silently costs an operator
| money, and round numbers cannot tell the two orders apart. Every assertion
| about it here uses a base price whose multiplier lands on a fractional cent.
|
| Round the unit, then multiply: €65.01 adult, 50% child = €32.51 each, €325.10
| for ten. Multiply and then round: €325.05 — five cents less than ten times the
| price the guest was shown, permanently, on every booking.
|
*/

function paxTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

/**
 * A product with an adult base band and a half-price child band, priced.
 *
 * @return array{0: RatePlan, 1: Collection<int, AgeBand>}
 */
function pricedBands(int $adultCents): array
{
    $product = Product::factory()->create();

    $adult = AgeBand::factory()->create(['product_id' => $product->getKey()]);
    $child = AgeBand::factory()->child()->create(['product_id' => $product->getKey()]);

    $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
    $plan->prices()->create(['age_band_id' => $adult->getKey(), 'price_cents' => $adultCents]);

    return [$plan->refresh(), $product->ageBands()->get()];
}

it('rounds the unit price before multiplying, so ten guests are ten unit prices', function (): void {
    paxTenant(function (): void {
        // 50% of 6501 is 3250.5. Half up at the unit gives 3251, and ten of
        // them is 32510 — which is exactly ten times what the guest was shown.
        [$plan, $bands] = pricedBands(6501);

        $lines = PaxLineBuilder::build($plan, $bands, ['adult' => 0, 'child' => 10]);

        expect($lines)->toHaveCount(1)
            ->and($lines[0]->unitPriceCents)->toBe(3251)
            ->and($lines[0]->totalCents)->toBe(32510)
            // The wrong order would give 32505, and the difference is the bug.
            ->and($lines[0]->totalCents)->not->toBe(32505);
    });
})->group('fast');

it('prices a multiplier band from the base band', function (): void {
    paxTenant(function (): void {
        [$plan, $bands] = pricedBands(6000);

        $lines = PaxLineBuilder::build($plan, $bands, ['adult' => 2, 'child' => 1]);

        expect($lines)->toHaveCount(2)
            ->and($lines[0]->totalCents)->toBe(12000)
            ->and($lines[1]->unitPriceCents)->toBe(3000)
            ->and($lines[1]->multiplierBp)->toBe(5000);
    });
})->group('fast');

it('lets an explicit price row beat the multiplier', function (): void {
    paxTenant(function (): void {
        // An operator who typed a price for this band on this plan meant it,
        // whatever the band's pricing mode says.
        [$plan, $bands] = pricedBands(6000);
        $child = $bands->firstWhere('code', 'child');

        $plan->prices()->create(['age_band_id' => $child?->getKey(), 'price_cents' => 1000]);

        $lines = PaxLineBuilder::build($plan->refresh(), $bands, ['child' => 2]);

        expect($lines[0]->unitPriceCents)->toBe(1000)
            ->and($lines[0]->totalCents)->toBe(2000);
    });
})->group('fast');

it('prices a fixed band from its own row', function (): void {
    paxTenant(function (): void {
        $product = Product::factory()->create();
        $adult = AgeBand::factory()->create(['product_id' => $product->getKey()]);
        $senior = AgeBand::factory()->fixedPrice()->create(['product_id' => $product->getKey()]);

        $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
        $plan->prices()->create(['age_band_id' => $adult->getKey(), 'price_cents' => 6000]);
        $plan->prices()->create(['age_band_id' => $senior->getKey(), 'price_cents' => 4500]);

        $lines = PaxLineBuilder::build($plan->refresh(), $product->ageBands()->get(), ['senior' => 2]);

        expect($lines[0]->unitPriceCents)->toBe(4500)
            // Not derived from anything, so no multiplier is recorded.
            ->and($lines[0]->multiplierBp)->toBeNull();
    });
})->group('fast');

it('still prices a band that takes no seat', function (): void {
    paxTenant(function (): void {
        // PRC-7: not counting toward capacity does not imply free. An infant is
        // usually free because the operator chose a multiplier of zero, not
        // because of the capacity flag — conflating the two would silently stop
        // charging for any band an operator decided not to count.
        $product = Product::factory()->create();
        $adult = AgeBand::factory()->create(['product_id' => $product->getKey()]);
        $infant = AgeBand::factory()->infant()->create(['product_id' => $product->getKey()]);
        $infant->update(['price_multiplier_bp' => 2500]);

        $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
        $plan->prices()->create(['age_band_id' => $adult->getKey(), 'price_cents' => 6000]);

        $lines = PaxLineBuilder::build($plan->refresh(), $product->ageBands()->get(), ['infant' => 2]);

        expect($lines[0]->unitPriceCents)->toBe(1500)
            ->and($lines[0]->totalCents)->toBe(3000);
    });
})->group('fast');

it('prices a zero-multiplier band at nothing without dropping the line', function (): void {
    paxTenant(function (): void {
        // The line has to survive: a guest reading a receipt needs to see that
        // the infant travelled, and the manifest needs the head count.
        $product = Product::factory()->create();
        $adult = AgeBand::factory()->create(['product_id' => $product->getKey()]);
        AgeBand::factory()->infant()->create(['product_id' => $product->getKey()]);

        $plan = RatePlan::factory()->create(['product_id' => $product->getKey()]);
        $plan->prices()->create(['age_band_id' => $adult->getKey(), 'price_cents' => 6000]);

        $lines = PaxLineBuilder::build($plan->refresh(), $product->ageBands()->get(), ['adult' => 2, 'infant' => 1]);

        expect($lines)->toHaveCount(2)
            ->and($lines[1]->ref)->toBe('infant')
            ->and($lines[1]->totalCents)->toBe(0);
    });
})->group('fast');

it('leaves out a band nobody booked', function (): void {
    paxTenant(function (): void {
        // "Infant × 0" is noise on a receipt a guest reads.
        [$plan, $bands] = pricedBands(6000);

        $lines = PaxLineBuilder::build($plan, $bands, ['adult' => 2]);

        expect($lines)->toHaveCount(1)->and($lines[0]->ref)->toBe('adult');
    });
})->group('fast');

it('carries the band label as it was, not a reference to fetch one', function (): void {
    paxTenant(function (): void {
        // §3.4: the snapshot must explain the total a year later without
        // touching another table. An operator renaming a band next spring must
        // not rewrite what a guest was shown last June.
        [$plan, $bands] = pricedBands(6000);

        $lines = PaxLineBuilder::build($plan, $bands, ['adult' => 1]);

        expect($lines[0]->label)->toBe(['el' => 'Ενήλικας', 'en' => 'Adult']);
    });
})->group('fast');
