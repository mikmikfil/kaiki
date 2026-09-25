<?php

declare(strict_types=1);

use App\Domain\Catalog\Support\ProductPublishChecklist;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\ProductResource\Pages\CreateProduct;
use App\Models\CancellationPolicy;
use App\Models\Port;
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
 * The wizard's price fields are keyed by the bands repeater's item keys — in
 * the browser, uuids. A repeater dehydrates to a plain list (0, 1, 2…), so
 * the saved bands and the typed prices no longer shared a key, every cell read
 * as empty, and the trip was made without a price list. The older tests filled
 * the bands as a list, so both sides were 0, 1… and the bug never showed.
 *
 * Here the bands are the page's own defaults, with the keys the browser has.
 */
it('keeps the prices and the period typed on a new trip, with the bands the page started with', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    [$season, $policy] = Tenancy::forTenant($tenant, fn (): array => [
        Season::factory()->create(),
        CancellationPolicy::factory()->create(['is_default' => true]),
    ]);

    tenancy()->initialize($tenant);

    $page = Livewire::actingAs($owner)->test(CreateProduct::class);

    // The default bands, keyed as the browser keys them.
    $keys = array_keys((array) $page->get('data.age_bands'));

    expect($keys)->not->toBeEmpty()
        ->and($keys[0])->toBeString();

    $period = 's' . $season->getKey();
    $prices = [];

    foreach ($keys as $i => $key) {
        $prices[$key] = ['new' => (string) (40 - $i * 10) . ',00', $period => (string) (50 - $i * 10) . ',00'];
    }

    $page->fillForm([
        'title' => ['el' => 'Κρουαζιέρα', 'en' => 'Cruise'],
        'slug' => 'cruise',
        'category' => ProductCategory::SharedFullDay->value,
        'mode' => BookingMode::PerSeat->value,
        'vessel_id' => Vessel::factory()->create(['capacity_max' => 12])->getKey(),
        'meeting_point_id' => Port::factory()->create()->getKey(),
        'cancellation_policy_id' => $policy->getKey(),
        'duration_minutes' => 480,
        'max_pax' => 12,
        'min_pax' => 1,
        'min_booking_pax' => 1,
        'wizard_publish' => 'publish',
        'wizard_seasons' => [$season->getKey()],
        'wizard_prices' => $prices,
    ])
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant($tenant, function () use ($season, $keys): void {
        $product = Product::query()->firstOrFail();

        $allYear = $product->ratePlans()->whereNull('season_id')->first();
        $summer = $product->ratePlans()->where('season_id', $season->getKey())->first();

        expect($allYear)->not->toBeNull()
            ->and($summer)->not->toBeNull()
            ->and($allYear->prices()->count())->toBe(count($keys))
            ->and($summer->prices()->count())->toBe(count($keys))
            ->and($allYear->prices()->orderBy('price_cents', 'desc')->value('price_cents'))->toBe(4000)
            ->and($product->status)->toBe(ProductStatus::Active);
    });
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
