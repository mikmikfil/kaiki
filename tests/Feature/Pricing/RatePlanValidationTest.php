<?php

declare(strict_types=1);

use App\Domain\Pricing\Actions\SaveRatePlan;
use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Rate plans — spec CAT-10, CAT-5, PRC-23, CNV-1
|--------------------------------------------------------------------------
|
| Four rules, all of them set-level or cross-table, none of them expressible as
| a column constraint — which is why they live in the Action and are tested
| through it rather than through a form.
|
| The one worth reading twice is the coverage rule. A `multiplier` band derives
| its price from the base band, so a missing row is fine; a `fixed` band has
| nothing to derive from, so a missing row is a passenger category that costs
| nothing and nobody notices until a family books.
|
*/

function inPlanTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function planInput(array $overrides = []): array
{
    return array_merge([
        'season_id' => null,
        'name' => 'Base',
        'deposit_type' => DepositType::None->value,
        'min_lead_time_hours' => 0,
        'is_active' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<int, int>|null  $bandPrices
 */
function savePlan(Product $product, array $attributes = [], ?array $bandPrices = null, ?RatePlan $plan = null): RatePlan
{
    return app(SaveRatePlan::class)($plan ?? new RatePlan, $product, planInput($attributes), $bandPrices);
}

/** A per-seat product with an adult base band and a child multiplier band. */
function seatProductWithBands(): Product
{
    $product = Product::factory()->create();

    AgeBand::factory()->create(['product_id' => $product->getKey()]);
    AgeBand::factory()->child()->create(['product_id' => $product->getKey()]);

    return $product;
}

it('saves a per-seat plan with a price for every band that needs one', function (): void {
    inPlanTenant(function (): void {
        $product = seatProductWithBands();
        $bands = $product->ageBands()->orderBy('sort_order')->get();

        $plan = savePlan($product, [], [
            $bands[0]->getKey() => 5000,
            $bands[1]->getKey() => 2500,
        ]);

        expect($plan->prices()->count())->toBe(2)
            ->and($plan->isDefault())->toBeTrue()
            ->and($plan->prices()->where('age_band_id', $bands[0]->getKey())->value('price_cents'))->toBe(5000);
    });
})->group('fast');

it('lets a multiplier band go without a price of its own', function (): void {
    inPlanTenant(function (): void {
        // The child band is 50% of the adult fare, so its row is derivable and
        // therefore optional. Requiring it would make every operator type the
        // same number twice and keep the two in step by hand.
        $product = seatProductWithBands();
        $base = $product->ageBands()->where('is_base', true)->firstOrFail();

        $plan = savePlan($product, [], [$base->getKey() => 5000]);

        expect($plan->prices()->count())->toBe(1);
    });
})->group('fast');

it('refuses a per-seat plan that leaves a fixed-price band unpriced', function (): void {
    inPlanTenant(function (): void {
        $product = Product::factory()->create();
        $base = AgeBand::factory()->create(['product_id' => $product->getKey()]);
        AgeBand::factory()->fixedPrice()->create(['product_id' => $product->getKey()]);

        savePlan($product, [], [$base->getKey() => 5000]);
    });
})->throws(ValidationException::class)->group('fast');

it('refuses a per-seat plan that leaves the base band unpriced', function (): void {
    inPlanTenant(function (): void {
        // Every multiplier is a multiple of this one number. Without it the
        // whole product prices at nothing, silently.
        $product = seatProductWithBands();
        $child = $product->ageBands()->where('is_base', false)->firstOrFail();

        savePlan($product, [], [$child->getKey() => 2500]);
    });
})->throws(ValidationException::class)->group('fast');

it('names every unpriced band in one message', function (): void {
    inPlanTenant(function (): void {
        $product = Product::factory()->create();
        AgeBand::factory()->create(['product_id' => $product->getKey()]);
        AgeBand::factory()->fixedPrice()->create(['product_id' => $product->getKey()]);

        try {
            savePlan($product, [], []);

            expect(false)->toBeTrue();
        } catch (ValidationException $e) {
            $message = implode(' ', $e->validator->errors()->get('prices'));

            // Localised band labels, in the locale the operator is reading —
            // «Ενήλικας» for a Greek session. The test suite runs in English.
            expect($message)->toContain('Adult')
                ->and($message)->toContain('Over 65');
        }
    });
})->group('fast');

it('requires a whole-boat price on a per-vessel plan', function (): void {
    inPlanTenant(function (): void {
        savePlan(Product::factory()->perVessel()->create(), [], null);
    });
})->throws(ValidationException::class)->group('fast');

it('refuses per-band prices on a per-vessel plan', function (): void {
    inPlanTenant(function (): void {
        // A whole-boat charter has one price. Rows here would be numbers no
        // pricing path ever reads.
        $product = Product::factory()->perVessel()->create();
        $band = AgeBand::factory()->create(['product_id' => $product->getKey()]);

        savePlan($product, ['vessel_price_cents' => 60000], [$band->getKey() => 5000]);
    });
})->throws(ValidationException::class)->group('fast');

it('refuses a whole-boat price on a per-seat plan', function (): void {
    inPlanTenant(function (): void {
        $product = seatProductWithBands();
        $base = $product->ageBands()->where('is_base', true)->firstOrFail();

        savePlan($product, ['vessel_price_cents' => 60000], [$base->getKey() => 5000]);
    });
})->throws(ValidationException::class)->group('fast');

it('saves a per-vessel plan with one price and no band rows', function (): void {
    inPlanTenant(function (): void {
        $plan = savePlan(
            Product::factory()->perVessel()->create(),
            ['vessel_price_cents' => 60000, 'extra_hour_price_cents' => 8000],
            [],
        );

        expect($plan->vessel_price_cents)->toBe(60000)
            ->and($plan->prices()->count())->toBe(0);
    });
})->group('fast');

it('permits a quote plan for internal reference without a guest-facing price', function (): void {
    inPlanTenant(function (): void {
        // CAT-5: a quote product never quotes itself. The plan is where the
        // operator keeps the deposit rule and the booking window they will
        // apply once they have agreed a figure by phone.
        $product = Product::factory()->create(['mode' => BookingMode::Quote]);

        $plan = savePlan($product, ['name' => 'Reference'], null);

        expect($plan->exists)->toBeTrue()
            ->and($plan->vessel_price_cents)->toBeNull()
            ->and($plan->prices()->count())->toBe(0);
    });
})->group('fast');

it('requires a percentage in 1 to 100 for a percentage deposit', function (?int $percent): void {
    inPlanTenant(function () use ($percent): void {
        $product = seatProductWithBands();
        $base = $product->ageBands()->where('is_base', true)->firstOrFail();

        savePlan(
            $product,
            ['deposit_type' => DepositType::Percent->value, 'deposit_percent' => $percent],
            [$base->getKey() => 5000],
        );
    });
})->with([[null], [0], [101]])->throws(ValidationException::class)->group('fast');

it('requires a positive amount for a fixed deposit', function (?int $cents): void {
    inPlanTenant(function () use ($cents): void {
        $product = seatProductWithBands();
        $base = $product->ageBands()->where('is_base', true)->firstOrFail();

        savePlan(
            $product,
            ['deposit_type' => DepositType::Fixed->value, 'deposit_fixed_cents' => $cents],
            [$base->getKey() => 5000],
        );
    });
})->with([[null], [0]])->throws(ValidationException::class)->group('fast');

it('refuses a deposit figure alongside payment in full', function (): void {
    inPlanTenant(function (): void {
        // Refused rather than cleared: the operator meant one of the two, and
        // only they know which.
        $product = seatProductWithBands();
        $base = $product->ageBands()->where('is_base', true)->firstOrFail();

        savePlan(
            $product,
            ['deposit_type' => DepositType::None->value, 'deposit_percent' => 30],
            [$base->getKey() => 5000],
        );
    });
})->throws(ValidationException::class)->group('fast');

it('clears the deposit column its type does not use', function (): void {
    inPlanTenant(function (): void {
        // Otherwise switching percent to fixed leaves a percentage behind, and
        // switching back resurrects a figure the operator thought was gone.
        $product = seatProductWithBands();
        $base = $product->ageBands()->where('is_base', true)->firstOrFail();

        $plan = savePlan(
            $product,
            ['deposit_type' => DepositType::Percent->value, 'deposit_percent' => 30],
            [$base->getKey() => 5000],
        );

        $plan = savePlan(
            $product,
            ['deposit_type' => DepositType::Fixed->value, 'deposit_fixed_cents' => 20000],
            [$base->getKey() => 5000],
            $plan,
        );

        expect($plan->deposit_percent)->toBeNull()
            ->and($plan->deposit_fixed_cents)->toBe(20000);
    });
})->group('fast');

it('replaces the price ladder rather than merging into it', function (): void {
    inPlanTenant(function (): void {
        // An operator who removes a band's price expects it gone, not shadowed
        // by the row from the previous save.
        $product = seatProductWithBands();
        $bands = $product->ageBands()->orderBy('sort_order')->get();

        $plan = savePlan($product, [], [
            $bands[0]->getKey() => 5000,
            $bands[1]->getKey() => 2500,
        ]);

        $plan = savePlan($product, [], [$bands[0]->getKey() => 6000], $plan);

        expect($plan->prices()->count())->toBe(1)
            ->and($plan->prices()->first()?->price_cents)->toBe(6000);
    });
})->group('fast');

it('leaves existing prices alone when none are supplied', function (): void {
    inPlanTenant(function (): void {
        // Null is "do not touch the prices" — what a partial update from the
        // API sends when it only changes the booking window.
        $product = seatProductWithBands();
        $base = $product->ageBands()->where('is_base', true)->firstOrFail();

        $plan = savePlan($product, [], [$base->getKey() => 5000]);
        $plan = savePlan($product, ['min_lead_time_hours' => 24], null, $plan);

        expect($plan->prices()->count())->toBe(1)
            ->and($plan->min_lead_time_hours)->toBe(24);
    });
})->group('fast');

it('stores every money column as an integer number of cents', function (): void {
    inPlanTenant(function (): void {
        $plan = savePlan(
            Product::factory()->perVessel()->create(),
            ['vessel_price_cents' => 60000, 'extra_hour_price_cents' => 8000, 'deposit_type' => DepositType::Fixed->value, 'deposit_fixed_cents' => 20000],
            [],
        );

        expect($plan->vessel_price_cents)->toBeInt()
            ->and($plan->extra_hour_price_cents)->toBeInt()
            ->and($plan->deposit_fixed_cents)->toBeInt();
    });
})->group('fast');

it('allows one plan per named season alongside the default', function (): void {
    inPlanTenant(function (): void {
        $product = seatProductWithBands();
        $base = $product->ageBands()->where('is_base', true)->firstOrFail();
        $season = Season::factory()->create();

        savePlan($product, [], [$base->getKey() => 5000]);
        savePlan($product, ['season_id' => $season->getKey()], [$base->getKey() => 7000]);

        expect(RatePlan::query()->where('product_id', $product->getKey())->count())->toBe(2);
    });
})->group('fast');
