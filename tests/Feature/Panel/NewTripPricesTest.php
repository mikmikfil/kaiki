<?php

declare(strict_types=1);

use App\Domain\Catalog\Support\ProductPublishChecklist;
use App\Domain\Pricing\Support\PriceTable;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\Season;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
 * Product owner, 2026-09-25: «Έφτιαξα μια εκδρομή και είχα βάλει κανονικά
 * τιμές… μου γράφει ότι λείπει ο τιμοκατάλογος», with the period unticked
 * afterwards.
 *
 * The wizard had a price grid of its own, keyed by the bands repeater's item
 * keys — in the browser, uuids — while the saved bands came back as a plain
 * list, so every cell read as empty and the trip was made without a price
 * list. The same day the wizard went (Mike: one form for creating and
 * editing): a new trip is made from «Βασικά», and its prices are typed in the
 * edit page's table, keyed by the saved bands themselves.
 *
 * So the regression is held on the flow that replaced it: the bands the create
 * page gave the trip, priced on the edit page, still priced — and still ticked
 * — when the page is opened again.
 */
it('keeps the prices and the period typed on a new trip, with the bands the create page gave it', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    $season = Tenancy::forTenant($tenant, fn (): Season => Season::factory()->create());

    tenancy()->initialize($tenant);

    // The operator's one boat, which the create page puts the draft on: the
    // boat is asked on «Πότε φεύγει» since 25/9, not here.
    Vessel::factory()->create(['capacity_max' => 12]);

    Livewire::actingAs($owner)->test(CreateProduct::class)
        ->fillForm([
            'title' => ['el' => 'Κρουαζιέρα', 'en' => 'Cruise'],
            'slug' => 'cruise',
            'category' => ProductCategory::SharedFullDay->value,
            'mode' => BookingMode::PerSeat->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->sole();

    $page = Livewire::actingAs($owner)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->call('togglePriceSeason', $season->getKey());

    // A row per band the create page made, keyed by the band.
    $rows = array_keys((array) $page->get('priceCells'));
    $period = PriceTable::seasonKey($season);

    expect($rows)->toHaveCount(3);

    foreach ($rows as $i => $row) {
        $page->set("priceCells.{$row}." . PriceTable::NEW_DEFAULT, (string) (40 - $i * 10) . ',00')
            ->set("priceCells.{$row}.{$period}", (string) (50 - $i * 10) . ',00');
    }

    $page->call('savePrices')->assertHasNoErrors();

    $allYear = $product->ratePlans()->whereNull('season_id')->first();
    $summer = $product->ratePlans()->where('season_id', $season->getKey())->first();

    expect($allYear)->not->toBeNull()
        ->and($summer)->not->toBeNull()
        ->and($allYear->prices()->count())->toBe(3)
        ->and($summer->prices()->count())->toBe(3)
        ->and($allYear->prices()->max('price_cents'))->toBe(4000)
        ->and(ProductPublishChecklist::satisfies($product->refresh(), ProductPublishChecklist::RATE_PLAN))->toBeTrue();

    // Opened again: the period is still ticked and every cell still holds its price.
    $again = Livewire::actingAs($owner)->test(EditProduct::class, ['record' => $product->getRouteKey()]);

    expect($again->get('priceSeasonIds'))->toBe([(int) $season->getKey()])
        ->and($again->get('priceCells.' . $rows[0] . '.' . PriceTable::columnKey($summer)))->toBe('50,00');
})->group('fast');

it('puts a missing price list on the «Τιμές» tab, not on «Βασικά»', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    Tenancy::forTenant($tenant, function (): void {
        $product = Product::factory()->create([
            'mode' => BookingMode::PerSeat,
            'status' => ProductStatus::Draft,
        ]);

        $unmet = ProductPublishChecklist::unmet($product);

        expect($unmet)->toContain(ProductPublishChecklist::RATE_PLAN);

        $count = static fn (string $tab): int => count(array_intersect($unmet, ProductResource::TAB_REQUIREMENTS[$tab]));
        $badge = static fn (int $n): ?string => $n === 0 ? null : trans_choice('catalog.product.tabs.missing', $n, ['count' => $n]);

        expect(ProductResource::missingBadge($product, 'prices'))->toBe($badge($count('prices')))
            ->and(ProductResource::missingBadge($product, 'prices'))->not->toBeNull()
            // «Βασικά» counts only its own: the price list is not one of them.
            ->and(ProductResource::basicsBadge($product))->toBe($badge($count('basics')));
    });
})->group('fast');

it('gives every publish requirement a tab', function (): void {
    expect(array_merge(...array_values(ProductResource::TAB_REQUIREMENTS)))
        ->toEqualCanonicalizing(ProductPublishChecklist::requirements());
})->group('fast');
