<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Filament\App\Resources\ProductResource\RelationManagers\RatePlansRelationManager;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\User;
use App\Support\Tenancy;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Οι τιμές μέσα στην εκδρομή
|--------------------------------------------------------------------------
|
| The question this screen exists to answer, asked out loud: «μια εκδρομή το
| καλοκαίρι 150 ευρώ, το χειμώνα 100 ευρώ». It used to take three screens and
| the trip chosen from a dropdown twice.
|
| What is asserted is the operator's path, not the arithmetic — `SaveRatePlan`
| owns the rules and has its own tests, and this screen deliberately decides
| nothing. What it must prove is that the product is never a field, that the
| Action's refusals still reach the operator, and that a period can be built
| without leaving.
|
*/

/**
 * A per-seat trip with a base band, which is what most trips are.
 *
 * @return array{0: User, 1: Product, 2: AgeBand}
 */
function pricedTrip(): array
{
    $owner = OperatorUser::withRole(Role::Owner);

    $product = null;
    $band = null;

    Tenancy::forTenant($owner->tenant, function () use (&$product, &$band): void {
        $product = Product::factory()->create();
        $band = AgeBand::query()->create([
            'product_id' => $product->getKey(),
            'code' => 'adult',
            'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'],
            'min_age' => 18,
            'is_base' => true,
            'pricing_mode' => 'fixed',
            'counts_toward_capacity' => true,
            'sort_order' => 0,
        ]);
    });

    return [$owner, $product, $band];
}

it('prices the same trip differently in two periods, from the trip', function (): void {
    [$owner, $product, $band] = pricedTrip();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($product, $band): void {
        $summer = Season::query()->create(['name' => ['el' => 'Καλοκαίρι', 'en' => 'Summer'], 'priority' => 10, 'is_active' => true]);
        $summer->dateRanges()->create(['starts_on' => '2027-06-01', 'ends_on' => '2027-09-30']);

        $winter = Season::query()->create(['name' => ['el' => 'Χειμώνας', 'en' => 'Winter'], 'priority' => 10, 'is_active' => true]);
        $winter->dateRanges()->create(['starts_on' => '2027-11-01', 'ends_on' => '2028-03-31']);

        $manager = Livewire::test(RatePlansRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ]);

        // What the operator types: euros, with the field turning them into
        // cents on the way to the database.
        foreach ([[$summer, '150'], [$winter, '100']] as [$season, $typed]) {
            $manager->callTableAction('create', data: [
                'season_id' => $season->getKey(),
                'band_prices' => [
                    ['age_band_id' => $band->getKey(), 'price_cents' => $typed],
                ],
                'is_active' => true,
                'deposit_type' => 'none',
            ])->assertHasNoTableActionErrors();
        }

        $plans = RatePlan::query()->where('product_id', $product->getKey())->get();

        expect($plans)->toHaveCount(2);

        $summerPlan = $plans->firstWhere('season_id', $summer->getKey());
        $winterPlan = $plans->firstWhere('season_id', $winter->getKey());

        expect($summerPlan->prices()->where('age_band_id', $band->getKey())->value('price_cents'))->toBe(15000)
            ->and($winterPlan->prices()->where('age_band_id', $band->getKey())->value('price_cents'))->toBe(10000)
            // The whole point: the trip was never chosen from a dropdown, and
            // both plans still belong to it.
            ->and($plans->pluck('product_id')->unique()->all())->toBe([$product->getKey()]);
    });
});

it('builds a period without leaving the trip', function (): void {
    [$owner, $product, $band] = pricedTrip();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($product): void {
        expect(Season::query()->count())->toBe(0);

        Livewire::test(RatePlansRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ]);

        $manager = new RatePlansRelationManager;
        $reflection = new ReflectionMethod($manager, 'createSeason');
        $id = $reflection->invoke($manager, [
            'name' => ['el' => 'Πάσχα', 'en' => 'Easter'],
            'starts_on' => '2027-04-10',
            'ends_on' => '2027-04-20',
        ]);

        $season = Season::query()->findOrFail($id);

        // A name with no dates matches nothing and prices nothing — it would
        // sit in the list looking like it works.
        expect($season->dateRanges()->count())->toBe(1)
            ->and($season->is_active)->toBeTrue();
    });
});

it('still refuses a second price for the same period', function (): void {
    [$owner, $product, $band] = pricedTrip();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($product, $band): void {
        $manager = Livewire::test(RatePlansRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ]);

        $row = ['age_band_id' => $band->getKey(), 'price_cents' => '120'];

        $manager->callTableAction('create', data: [
            'season_id' => null,
            'band_prices' => [$row],
            'is_active' => true,
            'deposit_type' => 'none',
        ])->assertHasNoTableActionErrors();

        // `SaveRatePlan` enforces one default plan per product in PHP, because
        // the unique index treats NULLs as distinct and always will. Moving the
        // screen must not have moved that.
        $manager->callTableAction('create', data: [
            'season_id' => null,
            'band_prices' => [$row],
            'is_active' => true,
            'deposit_type' => 'none',
        ]);

        expect(RatePlan::query()->where('product_id', $product->getKey())->count())->toBe(1);
    });
});

it('is closed to crew', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    $product = null;

    Tenancy::forTenant($crew->tenant, function () use (&$product): void {
        $product = Product::factory()->create();
    });

    actingAs($crew);

    Tenancy::forTenant($crew->tenant, function () use ($product): void {
        // TEN-8: the catalogue is an owner's and a manager's. Crew read
        // departures, not prices.
        expect(RatePlansRelationManager::canViewForRecord($product, EditProduct::class))->toBeFalse();
    });
});
