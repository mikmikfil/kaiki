<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveAgeBands;
use App\Domain\Pricing\Actions\ComputePrice;
use App\Domain\Pricing\Actions\ConvertPercentPricesToEuros;
use App\Domain\Pricing\Actions\SavePriceTable;
use App\Domain\Pricing\Support\PriceTable;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\PriceQuickFill;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Season;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| A trip's prices in euros, one table (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| «Κανείς δεν σκέφτεται 5000. Σκέφτεται: το παιδί πληρώνει 22,50 €.» Every band,
| every period, typed in euros; the quick buttons write euros; the old
| percentages are converted once, to exactly the fare a guest pays today, and
| a booking already taken does not move by a cent.
|
*/

function tableTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

/**
 * An adult base band and a half-price child band, both still percentages, on
 * a default plan (adult priced) and a summer plan (adult priced).
 *
 * @return array{product: Product, adult: AgeBand, child: AgeBand, default: RatePlan, summer: RatePlan}
 */
function percentTrip(int $defaultAdult = 4500, int $summerAdult = 6501): array
{
    $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);
    $adult = AgeBand::factory()->create(['product_id' => $product->getKey()]);
    $child = AgeBand::factory()->child()->create(['product_id' => $product->getKey()]);

    $default = RatePlan::factory()->create(['product_id' => $product->getKey()]);
    $default->prices()->create(['age_band_id' => $adult->getKey(), 'price_cents' => $defaultAdult]);

    $summer = RatePlan::factory()->forSeason(Season::factory()->create())->create(['product_id' => $product->getKey()]);
    $summer->prices()->create(['age_band_id' => $adult->getKey(), 'price_cents' => $summerAdult]);

    return compact('product', 'adult', 'child', 'default', 'summer');
}

it('writes euros from the quick buttons, rounded half up to the cent', function (): void {
    // 6501 is the fractional cent: half is 3250.5, and the engine charges 3251.
    expect(PriceQuickFill::Half->apply(6501))->toBe(3251)
        ->and(PriceQuickFill::Same->apply(4500))->toBe(4500)
        ->and(PriceQuickFill::LessThirty->apply(4500))->toBe(3150)
        // 70% of 4999 is 3499.3: down, not up.
        ->and(PriceQuickFill::LessThirty->apply(4999))->toBe(3499)
        // 70% of 4995 is 3496.5: half up.
        ->and(PriceQuickFill::LessThirty->apply(4995))->toBe(3497)
        ->and(PriceQuickFill::Free->apply(4500))->toBe(0);
})->group('fast');

it('shows a percentage band as the euros the engine charges, marked as still derived', function (): void {
    tableTenant(function (): void {
        ['product' => $product, 'child' => $child, 'summer' => $summer] = percentTrip();

        $table = PriceTable::for($product);
        $row = PriceTable::rowKey($child);
        $column = PriceTable::columnKey($summer);

        expect($table->cents[$row][$column])->toBe(3251)
            ->and($table->derived[$row][$column])->toBeTrue()
            // The default plan is the first column.
            ->and($table->columns[0]['plan_id'])->not->toBeNull()
            ->and($table->columns[0]['key'])->not->toBe($column);
    });
})->group('fast');

it('saves every band on every period as euros, and stops the band being a percentage', function (): void {
    tableTenant(function (): void {
        ['product' => $product, 'adult' => $adult, 'child' => $child, 'default' => $default, 'summer' => $summer] = percentTrip();

        app(SavePriceTable::class)($product, [
            PriceTable::rowKey($adult) => [PriceTable::columnKey($default) => 4500, PriceTable::columnKey($summer) => 6000],
            PriceTable::rowKey($child) => [PriceTable::columnKey($default) => 2250, PriceTable::columnKey($summer) => 2800],
        ]);

        expect(RatePlanPrice::query()->where('rate_plan_id', $summer->getKey())->pluck('price_cents', 'age_band_id')->all())
            ->toBe([$adult->getKey() => 6000, $child->getKey() => 2800])
            ->and(RatePlanPrice::query()->where('rate_plan_id', $default->getKey())->where('age_band_id', $child->getKey())->value('price_cents'))->toBe(2250)
            ->and($child->fresh()->pricing_mode)->toBe(AgeBandPricing::Fixed)
            ->and($child->fresh()->price_multiplier_bp)->toBeNull()
            // Plan settings are the table's to leave alone.
            ->and($summer->fresh()->season_id)->toBe($summer->season_id);
    });
})->group('fast');

it('creates the default plan when a trip has no prices yet', function (): void {
    tableTenant(function (): void {
        $product = Product::factory()->create(['mode' => BookingMode::PerSeat]);
        $adult = AgeBand::factory()->create(['product_id' => $product->getKey(), 'pricing_mode' => AgeBandPricing::Fixed, 'price_multiplier_bp' => null]);

        expect(PriceTable::for($product)->columns[0]['key'])->toBe(PriceTable::NEW_DEFAULT);

        app(SavePriceTable::class)($product, [PriceTable::rowKey($adult) => [PriceTable::NEW_DEFAULT => 5000]]);

        $plan = RatePlan::query()->where('product_id', $product->getKey())->sole();

        expect($plan->season_id)->toBeNull()
            ->and($plan->prices()->value('price_cents'))->toBe(5000);
    });
})->group('fast');

it('refuses a blank cell, naming the band and the period, and writes nothing', function (): void {
    tableTenant(function (): void {
        ['product' => $product, 'adult' => $adult, 'child' => $child, 'default' => $default, 'summer' => $summer] = percentTrip();

        expect(fn () => app(SavePriceTable::class)($product, [
            PriceTable::rowKey($adult) => [PriceTable::columnKey($default) => 4500, PriceTable::columnKey($summer) => 6000],
            PriceTable::rowKey($child) => [PriceTable::columnKey($default) => 2250, PriceTable::columnKey($summer) => null],
        ]))->toThrow(ValidationException::class, (string) $child->label);

        expect(RatePlanPrice::query()->where('age_band_id', $child->getKey())->exists())->toBeFalse()
            ->and($child->fresh()->pricing_mode)->toBe(AgeBandPricing::Multiplier);
    });
})->group('fast');

it('fills, asks after an adult change, and saves from the trip page', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    $trip = Tenancy::forTenant($tenant, fn (): array => percentTrip(4500, 6501));
    $adultRow = PriceTable::rowKey($trip['adult']);
    $childRow = PriceTable::rowKey($trip['child']);
    $summer = PriceTable::columnKey($trip['summer']);
    $default = PriceTable::columnKey($trip['default']);

    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(EditProduct::class, ['record' => $trip['product']->uuid])
        // Loaded as the engine's euros, the percentage already worked out.
        ->assertSet("priceCells.{$childRow}.{$summer}", '32,51')
        ->call('fillPriceRow', $childRow, PriceQuickFill::LessThirty->value)
        ->assertSet("priceCells.{$childRow}.{$default}", '31,50')
        // The adult goes up: the child is not rewritten, it is offered.
        ->set("priceCells.{$adultRow}.{$default}", '50,00')
        ->assertSet("priceCells.{$childRow}.{$default}", '31,50')
        ->assertSet("priceStale.{$childRow}", true)
        ->assertSee(__('pricing.price_table.stale_action'))
        ->call('refreshPriceRow', $childRow)
        ->assertSet("priceCells.{$childRow}.{$default}", '35,00')
        ->call('savePrices')
        ->assertHasNoErrors()
        ->assertNotified(__('pricing.price_table.saved'));

    Tenancy::forTenant($tenant, function () use ($trip): void {
        expect(RatePlanPrice::query()->where('rate_plan_id', $trip['default']->getKey())->pluck('price_cents', 'age_band_id')->all())
            ->toBe([$trip['adult']->getKey() => 5000, $trip['child']->getKey() => 3500]);
    });
})->group('fast');

it('says which cell is not an amount, and saves nothing', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);
    $trip = Tenancy::forTenant($tenant, fn (): array => percentTrip());
    $cell = 'priceCells.' . PriceTable::rowKey($trip['child']) . '.' . PriceTable::columnKey($trip['summer']);

    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(EditProduct::class, ['record' => $trip['product']->uuid])
        ->set($cell, 'είκοσι')
        ->call('savePrices')
        ->assertHasErrors([$cell]);

    Tenancy::forTenant($tenant, function () use ($trip): void {
        expect(RatePlanPrice::query()->where('age_band_id', $trip['child']->getKey())->exists())->toBeFalse();
    });
})->group('fast');

it('keeps every price when the trip form is saved again', function (): void {
    // The regression under all of this: bands used to be deleted and recreated
    // on every save, and prices cascade on the band. «Αποθήκευση» wiped them.
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);
    $trip = Tenancy::forTenant($tenant, fn (): array => percentTrip());

    Tenancy::forTenant($tenant, function () use ($trip): void {
        $bands = $trip['product']->ageBands()->get()->map(static fn (AgeBand $band): array => [
            'code' => $band->code,
            'label' => $band->getTranslations('label'),
            'min_age' => $band->min_age,
            'max_age' => $band->max_age,
            'counts_toward_capacity' => $band->counts_toward_capacity,
            'pricing_mode' => $band->pricing_mode->value,
            'price_multiplier_bp' => $band->price_multiplier_bp,
            'is_base' => $band->is_base,
            'requires_adult' => $band->requires_adult,
        ])->all();

        app(SaveAgeBands::class)($trip['product'], $bands);

        expect(RatePlanPrice::query()->whereIn('rate_plan_id', [$trip['default']->getKey(), $trip['summer']->getKey()])->count())->toBe(2)
            ->and($trip['adult']->fresh())->not->toBeNull();
    });
})->group('fast');

it('converts percentages to the exact euros guests pay today, once', function (): void {
    tableTenant(function (): void {
        ['product' => $product, 'child' => $child, 'summer' => $summer, 'default' => $default] = percentTrip(4500, 6501);

        $preview = app(ConvertPercentPricesToEuros::class)(false);

        expect($preview['planned'])->toHaveCount(2)
            ->and(RatePlanPrice::query()->where('age_band_id', $child->getKey())->exists())->toBeFalse();

        $result = app(ConvertPercentPricesToEuros::class)(true);

        expect($result['products'])->toBe(1)
            ->and(RatePlanPrice::query()->where('rate_plan_id', $summer->getKey())->where('age_band_id', $child->getKey())->value('price_cents'))->toBe(3251)
            ->and(RatePlanPrice::query()->where('rate_plan_id', $default->getKey())->where('age_band_id', $child->getKey())->value('price_cents'))->toBe(2250)
            ->and($child->fresh()->pricing_mode)->toBe(AgeBandPricing::Fixed)
            ->and(app(ConvertPercentPricesToEuros::class)(true)['planned'])->toBe([]);
    });
})->group('fast');

it('leaves a trip untouched when a period has no adult price to take a share of', function (): void {
    tableTenant(function (): void {
        ['product' => $product, 'adult' => $adult, 'child' => $child, 'summer' => $summer] = percentTrip();
        RatePlanPrice::query()->where('rate_plan_id', $summer->getKey())->where('age_band_id', $adult->getKey())->delete();

        $result = app(ConvertPercentPricesToEuros::class)(true);

        expect($result['skipped'])->toHaveCount(1)
            ->and($result['products'])->toBe(0)
            ->and(RatePlanPrice::query()->where('age_band_id', $child->getKey())->exists())->toBeFalse()
            ->and($child->fresh()->pricing_mode)->toBe(AgeBandPricing::Multiplier);
    });
})->group('fast');

it('changes no booking and no quote: the converted fare is the fare', function (): void {
    tableTenant(function (): void {
        ['product' => $product] = percentTrip(6501, 6501);
        $date = Carbon::now()->addDays(20);
        $party = ['adult' => 2, 'child' => 3];

        $before = app(ComputePrice::class)($product, $date, $party);

        $booking = Booking::factory()->create([
            'product_id' => $product->getKey(),
            'total_cents' => $before->totalCents,
            'subtotal_cents' => $before->totalCents,
            'price_snapshot' => $before->snapshot->toArray(),
        ]);
        $stored = $booking->fresh()->only(['price_snapshot', 'total_cents', 'subtotal_cents', 'pax_breakdown']);

        app(ConvertPercentPricesToEuros::class)(true);

        $after = app(ComputePrice::class)($product->fresh(), $date, $party);

        expect($after->totalCents)->toBe($before->totalCents)
            ->and($booking->fresh()->only(['price_snapshot', 'total_cents', 'subtotal_cents', 'pax_breakdown']))->toBe($stored);
    });
})->group('fast');
