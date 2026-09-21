<?php

declare(strict_types=1);

use App\Domain\Pricing\Support\PlanSummary;
use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Filament\App\Resources\RatePlanResource\Pages\ListRatePlans;
use App\Filament\App\Widgets\UnsellableProducts;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Season;
use App\Models\SeasonDateRange;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The catalogue's prices, read as a summary (product owner, 2026-09-21)
|--------------------------------------------------------------------------
|
| «Η τάδε εκδρομή κοστίζει τόσο τη θερινή περίοδο, τόσα άτομα.» The «Τιμές»
| screen listed price lists without a price in them; direction Α of the
| mockup made it answer that sentence. These tests are the sentence.
|
| PlanSummary reads and never resolves — so every case here builds rows and
| asks what the line says, never what a guest would be charged.
|
*/

/**
 * A trip sold per seat, with an adult band and a child band.
 *
 * @param  array<string, mixed>  $attributes
 */
function summaryTrip(array $attributes = []): Product
{
    $product = Product::factory()->create([
        'mode' => BookingMode::PerSeat,
        'status' => ProductStatus::Active,
        'duration_minutes' => 180,
        'max_pax' => 12,
        ...$attributes,
    ]);

    AgeBand::factory()->for($product)->create(['code' => 'adult', 'label' => 'Ενήλικας', 'is_base' => true, 'sort_order' => 1]);
    AgeBand::factory()->for($product)->create(['code' => 'child', 'label' => 'Παιδί', 'is_base' => false, 'sort_order' => 2]);

    return $product->fresh(['ageBands']);
}

/**
 * @param  array<string, int>  $prices  band code => cents
 * @param  array<string, mixed>  $attributes
 */
function summaryPlan(Product $product, ?Season $season, array $prices, array $attributes = []): RatePlan
{
    $plan = RatePlan::factory()->for($product)->create([
        'season_id' => $season?->getKey(),
        'vessel_price_cents' => null,
        ...$attributes,
    ]);

    foreach ($prices as $code => $cents) {
        RatePlanPrice::factory()->for($plan)->create([
            'age_band_id' => $product->ageBands->firstWhere('code', $code)?->getKey(),
            'price_cents' => $cents,
        ]);
    }

    return $plan->fresh(['prices', 'product.ageBands', 'season.dateRanges']);
}

it('leads with the base band\'s price, which is the figure a guest sees as «από»', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $trip = summaryTrip();
        $plan = summaryPlan($trip, null, ['adult' => 6500, 'child' => 3800]);

        expect(PlanSummary::headlineCents($plan))->toBe(6500)
            ->and(PlanSummary::headline($plan))->toContain('65');
    });
})->group('fast');

it('puts the other bands under the period, never the base one twice', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $trip = summaryTrip();
        $plan = summaryPlan($trip, null, ['adult' => 6500, 'child' => 3800]);

        $detail = PlanSummary::detail($plan);

        expect($detail)->toContain('Παιδί')
            ->and($detail)->not->toContain('Ενήλικας');
    });
})->group('fast');

it('reports no price rather than zero when the bands have not been priced yet', function (): void {
    // The plan an operator is looking for on this screen. «0,00 €» here would
    // read as a free trip, which is the same mistake `price_from_cents` avoids.
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $trip = summaryTrip();
        $plan = summaryPlan($trip, null, []);

        expect(PlanSummary::headlineCents($plan))->toBeNull()
            ->and(PlanSummary::headline($plan))->toBeNull();
    });
})->group('fast');

it('leads a whole-boat charter with the boat\'s price and its terms', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $charter = Product::factory()->create(['mode' => BookingMode::PerVessel, 'duration_minutes' => 360]);

        $plan = RatePlan::factory()->for($charter)->create([
            'season_id' => null,
            'vessel_price_cents' => 48000,
            'included_pax' => 8,
            'extra_pax_price_cents' => 4500,
            'extra_hour_price_cents' => 9000,
        ])->fresh(['prices', 'product.ageBands']);

        expect(PlanSummary::headlineCents($plan))->toBe(48000)
            ->and(PlanSummary::detail($plan))->toContain('8')
            ->and(PlanSummary::detail($plan))->toContain('45')
            ->and(PlanSummary::detail($plan))->toContain('90');
    });
})->group('fast');

it('shows the first date range and counts the rest, never dropping one', function (): void {
    // A season has many ranges: «Θερινή» can be June to September *and* the
    // fortnight either side of Easter. An operator reading one range would
    // otherwise believe it was the only one.
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $trip = summaryTrip();
        $season = Season::factory()->create(['name' => ['el' => 'Θερινή', 'en' => 'Summer']]);
        SeasonDateRange::factory()->for($season)->create(['starts_on' => '2026-06-01', 'ends_on' => '2026-09-15']);
        SeasonDateRange::factory()->for($season)->create(['starts_on' => '2027-06-01', 'ends_on' => '2027-09-15']);

        $plan = summaryPlan($trip, $season, ['adult' => 6500]);

        $dates = PlanSummary::dates($plan);

        expect($dates)->toContain('2026')
            ->and($dates)->toContain(trans_choice('pricing.rate_plan.table.more_dates', 1, ['count' => 1]));
    });
})->group('fast');

it('names the default plan rather than leaving the period blank', function (): void {
    // `season_id` is null on the default plan, and a null state is exactly what
    // Filament renders as a placeholder — which left the one row that matters
    // most as an empty cell.
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $trip = summaryTrip();
        $plan = summaryPlan($trip, null, ['adult' => 4500]);

        expect(PlanSummary::period($plan))->toBe(__('pricing.on_product.season.default'))
            ->and(PlanSummary::dates($plan))->toBe('—');
    });
})->group('fast');

it('says the deposit as a figure, not as the name of a type', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $trip = summaryTrip();

        $percent = summaryPlan($trip, null, ['adult' => 4500], ['deposit_type' => DepositType::Percent, 'deposit_percent' => 30]);
        $fixed = summaryPlan($trip, null, ['adult' => 4500], ['deposit_type' => DepositType::Fixed, 'deposit_fixed_cents' => 20000]);
        $none = summaryPlan($trip, null, ['adult' => 4500], ['deposit_type' => DepositType::None]);

        expect(PlanSummary::deposit($percent))->toBe('30%')
            ->and(PlanSummary::deposit($fixed))->toContain('200')
            ->and(PlanSummary::deposit($none))->toBe(__('pricing.rate_plan.table.deposit_none'));
    });
})->group('fast');

it('carries the boat, the people, the day and the way it sells under the trip\'s name', function (): void {
    Tenancy::forTenant(Tenant::factory()->create(), function (): void {
        $vessel = Vessel::factory()->create(['name' => 'Ναυσικά']);
        $trip = summaryTrip(['vessel_id' => $vessel->getKey(), 'max_pax' => 12, 'duration_minutes' => 180]);

        $meta = PlanSummary::productMeta($trip->fresh(['vessel']));

        expect($meta)->toContain('Ναυσικά')
            ->and($meta)->toContain('12')
            ->and($meta)->toContain(trans_choice('pricing.rate_plan.table.hours', 3, ['count' => 3]))
            ->and($meta)->toContain(BookingMode::PerSeat->label());
    });
})->group('fast');

it('reads a duration in hours when it is whole hours, and never makes the reader divide', function (int $minutes, string $expected): void {
    app()->setLocale('el');

    expect(PlanSummary::duration($minutes))->toBe($expected);
})->with([
    'whole hours' => [180, '3 ώρες'],
    'one hour' => [60, '1 ώρα'],
    'under an hour' => [45, '45 λεπτά'],
    'hours and minutes' => [90, '1 ώρα 30 λεπτά'],
])->group('fast');

it('groups the screen by trip and shows the price in it', function (): void {
    $owner = OperatorUser::withRole(Role::Owner, Tenant::factory()->create());

    Tenancy::forTenant($owner->tenant, function (): void {
        $vessel = Vessel::factory()->create(['name' => 'Ναυσικά']);
        $trip = summaryTrip([
            'vessel_id' => $vessel->getKey(),
            'title' => ['el' => 'Ηλιοβασίλεμα στη Χώρα', 'en' => 'Sunset at Chora'],
        ]);
        summaryPlan($trip, null, ['adult' => 4500, 'child' => 2600]);
    });

    tenancy()->initialize($owner->tenant);
    // In Greek, because the trip's Greek title and the euro format are both
    // what an operator reads — the suite's default locale is English.
    app()->setLocale('el');

    Livewire::actingAs($owner)
        ->test(ListRatePlans::class)
        ->assertOk()
        ->assertSee('Ηλιοβασίλεμα στη Χώρα')
        ->assertSee('Ναυσικά')
        // The point of the screen: the number, on the row.
        ->assertSee(MoneyFormatter::format(4500))
        ->assertSee(__('pricing.on_product.season.default'));
})->group('fast');

it('puts the trips that cannot sell above the ones that can', function (): void {
    // A table of plans cannot show a trip that has none, and that is the trip
    // an operator opens this screen to find.
    $owner = OperatorUser::withRole(Role::Owner, Tenant::factory()->create());

    Tenancy::forTenant($owner->tenant, function (): void {
        Product::factory()->create([
            'title' => ['el' => 'Νυχτερινή ψαρική', 'en' => 'Night fishing'],
            'mode' => BookingMode::PerSeat,
            'status' => ProductStatus::Active,
            'price_from_cents' => null,
        ]);
    });

    tenancy()->initialize($owner->tenant);
    app()->setLocale('el');

    Livewire::actingAs($owner)
        ->test(UnsellableProducts::class)
        ->assertOk()
        ->assertSee('Νυχτερινή ψαρική');
})->group('fast');
