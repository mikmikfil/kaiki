<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveAgeBands;
use App\Domain\Catalog\Support\AgeBandResolver;
use App\Domain\Pricing\Actions\SavePeriodTerms;
use App\Domain\Pricing\Actions\SavePriceTable;
use App\Domain\Pricing\Support\PriceTable;
use App\Enums\AgeBandKind;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\Tenant;
use App\Support\Tenancy;

/*
|--------------------------------------------------------------------------
| Prices by group and period — product owner, 2026-09-24
|--------------------------------------------------------------------------
|
| Approved from docs/mockups/pricing-flow.html: make the groups (by age or by
| status), tick the periods that have another price, fill one table, set the
| deposit and deadlines once. A period may have its own terms.
|
*/

/** @return array{product: Product, adult: AgeBand, child: AgeBand, summer: Season, autumn: Season} */
function flowTrip(): array
{
    $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);
    $fixed = ['product_id' => $product->getKey(), 'pricing_mode' => AgeBandPricing::Fixed, 'price_multiplier_bp' => null];
    $adult = AgeBand::factory()->create($fixed + ['is_base' => true]);
    $child = AgeBand::factory()->child()->create($fixed + ['is_base' => false]);

    return [
        'product' => $product,
        'adult' => $adult,
        'child' => $child,
        'summer' => Season::factory()->create(),
        'autumn' => Season::factory()->create(),
    ];
}

/** @param  array<string, int>  $byColumn  column key => adult cents; the child pays half */
function flowCents(AgeBand $adult, AgeBand $child, array $byColumn): array
{
    $cents = [];

    foreach ($byColumn as $key => $value) {
        $cents[PriceTable::rowKey($adult)][$key] = $value;
        $cents[PriceTable::rowKey($child)][$key] = intdiv($value, 2);
    }

    return $cents;
}

it('turns a ticked period into a plan on save, under the trip\'s terms', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        ['product' => $product, 'adult' => $adult, 'child' => $child, 'summer' => $summer] = flowTrip();

        $table = PriceTable::for($product, [(int) $summer->getKey()]);

        expect(array_column($table->columns, 'key'))->toBe([PriceTable::NEW_DEFAULT, PriceTable::seasonKey($summer)]);

        app(SavePriceTable::class)(
            $product,
            flowCents($adult, $child, [PriceTable::NEW_DEFAULT => 4000, PriceTable::seasonKey($summer) => 6000]),
            [(int) $summer->getKey()],
            ['deposit_type' => DepositType::Percent->value, 'deposit_percent' => 30, 'min_lead_time_hours' => 12],
        );

        $plans = RatePlan::query()->where('product_id', $product->getKey())->get();
        $summerPlan = $plans->firstWhere('season_id', $summer->getKey());

        expect($plans)->toHaveCount(2)
            ->and($summerPlan->is_active)->toBeTrue()
            ->and($summerPlan->follows_trip_terms)->toBeTrue()
            ->and($summerPlan->deposit_type)->toBe(DepositType::Percent)
            ->and($summerPlan->deposit_percent)->toBe(30)
            ->and($summerPlan->min_lead_time_hours)->toBe(12)
            ->and($summerPlan->prices()->where('age_band_id', $child->getKey())->value('price_cents'))->toBe(3000);
    });
})->group('fast');

it('switches an unticked period off and keeps its prices for when it comes back', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        ['product' => $product, 'adult' => $adult, 'child' => $child, 'summer' => $summer] = flowTrip();

        app(SavePriceTable::class)(
            $product,
            flowCents($adult, $child, [PriceTable::NEW_DEFAULT => 4000, PriceTable::seasonKey($summer) => 6000]),
            [(int) $summer->getKey()],
        );

        $default = RatePlan::query()->where('product_id', $product->getKey())->whereNull('season_id')->sole();

        app(SavePriceTable::class)($product, flowCents($adult, $child, [PriceTable::columnKey($default) => 4500]), []);

        $summerPlan = RatePlan::query()->where('season_id', $summer->getKey())->sole();

        expect($summerPlan->is_active)->toBeFalse()
            ->and($summerPlan->prices()->where('age_band_id', $adult->getKey())->value('price_cents'))->toBe(6000);

        // Ticked again: the column comes back with the kept prices.
        $table = PriceTable::for($product, [(int) $summer->getKey()]);

        expect($table->cents[PriceTable::rowKey($adult)][PriceTable::columnKey($summerPlan)])->toBe(6000);
    });
})->group('fast');

it('writes the trip\'s terms to every period that follows them, and not to one with its own', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        ['product' => $product, 'adult' => $adult, 'child' => $child, 'summer' => $summer, 'autumn' => $autumn] = flowTrip();
        $ticked = [(int) $summer->getKey(), (int) $autumn->getKey()];

        app(SavePriceTable::class)(
            $product,
            flowCents($adult, $child, [
                PriceTable::NEW_DEFAULT => 4000,
                PriceTable::seasonKey($summer) => 6000,
                PriceTable::seasonKey($autumn) => 5000,
            ]),
            $ticked,
        );

        $summerPlan = RatePlan::query()->where('season_id', $summer->getKey())->sole();
        $autumnPlan = RatePlan::query()->where('season_id', $autumn->getKey())->sole();

        // Summer asks for a fixed €100 deposit of its own.
        app(SavePeriodTerms::class)($summerPlan, ['deposit_type' => DepositType::Fixed->value, 'deposit_fixed_cents' => 10000]);

        $cents = flowCents($adult, $child, [
            'p' . RatePlan::query()->whereNull('season_id')->where('product_id', $product->getKey())->value('id') => 4000,
            PriceTable::columnKey($summerPlan) => 6000,
            PriceTable::columnKey($autumnPlan) => 5000,
        ]);

        app(SavePriceTable::class)($product, $cents, $ticked, ['deposit_type' => DepositType::Percent->value, 'deposit_percent' => 20]);

        expect($autumnPlan->refresh()->deposit_percent)->toBe(20)
            ->and($summerPlan->refresh()->follows_trip_terms)->toBeFalse()
            ->and($summerPlan->deposit_type)->toBe(DepositType::Fixed)
            ->and($summerPlan->deposit_fixed_cents)->toBe(10000);

        // Back to the trip's terms: copied over, and following again.
        app(SavePeriodTerms::class)($summerPlan, null);

        expect($summerPlan->refresh()->follows_trip_terms)->toBeTrue()
            ->and($summerPlan->deposit_type)->toBe(DepositType::Percent)
            ->and($summerPlan->deposit_percent)->toBe(20);
    });
})->group('fast');

it('keeps a group by status out of every age check, and stores no ages for it', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);

        app(SaveAgeBands::class)($product, [
            ['code' => 'adult', 'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'], 'min_age' => 12, 'is_base' => true, 'pricing_mode' => 'fixed'],
            ['code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'min_age' => 3, 'max_age' => 11, 'pricing_mode' => 'fixed'],
            ['code' => 'student', 'label' => ['el' => 'Φοιτητής', 'en' => 'Student'], 'kind' => 'status', 'requires_proof' => true, 'min_age' => 18, 'max_age' => 25, 'pricing_mode' => 'fixed'],
        ]);

        $student = AgeBand::query()->where('code', 'student')->sole();

        expect($student->kind)->toBe(AgeBandKind::Status)
            ->and($student->requires_proof)->toBeTrue()
            ->and($student->min_age)->toBe(0)
            ->and($student->max_age)->toBeNull()
            // Any age at checkout: the card at boarding is the check.
            ->and($student->covers(40))->toBeTrue()
            // Never the answer to «which group is a nine-year-old».
            ->and(AgeBandResolver::forAge($product->ageBands()->get(), 9)?->code)->toBe('child')
            ->and(AgeBandResolver::forAge($product->ageBands()->get(), 40)?->code)->toBe('adult');
    });
})->group('fast');
