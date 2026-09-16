<?php

declare(strict_types=1);

use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\getJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| `badge`: the operator's label on a trip card's photograph (2026-09-16)
|--------------------------------------------------------------------------
|
| «Δημοφιλές», «Για δύο». The WordPress plugin kept these in its own post meta
| until the hosted cards needed them too; the product now carries one, and the
| API sends it on the list, the detail and the sync feed.
|
| Two rules, each asserted: it is **resolved to the negotiated locale** like
| every other piece of product prose, and it is **null when the operator set
| none** — never the category, which is what a card would otherwise be tempted
| to print instead.
|
*/

/** @return array{0: Tenant, 1: string} */
function badgeCatalog(ApiKeyType $type = ApiKeyType::Publishable): array
{
    $tenant = Tenant::factory()->create(['default_locale' => 'el']);

    [, $key] = CatalogRequest::key($tenant, $type, [ApiScope::ProductsRead]);

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create([
            'slug' => 'labelled',
            'sort_order' => 1,
            'title' => ['el' => 'Ελληνικός τίτλος', 'en' => 'English title'],
            'badge' => ['el' => 'Δημοφιλές', 'en' => 'Popular'],
        ]);

        Product::factory()->create([
            'slug' => 'unlabelled',
            'sort_order' => 2,
            'category' => ProductCategory::Sunset,
        ]);
    });

    return [$tenant, $key];
}

/**
 * @param  array<string, mixed>  $rows
 * @return array<string, mixed>
 */
function badgeRow(array $rows, string $slug): array
{
    foreach ($rows as $row) {
        if (($row['slug'] ?? null) === $slug) {
            return $row;
        }
    }

    throw new RuntimeException("No row for {$slug}.");
}

it('sends the badge on the product list, in the requested locale', function (string $locale, string $expected): void {
    [, $key] = badgeCatalog();

    $rows = getJson(CatalogRequest::url('/products', ['locale' => $locale]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->json('data');

    expect(badgeRow($rows, 'labelled')['badge'])->toBe($expected);
})->with([
    'greek' => ['el', 'Δημοφιλές'],
    'english' => ['en', 'Popular'],
])->group('fast');

it('sends null, and not the category, for a trip with no badge', function (): void {
    [, $key] = badgeCatalog();

    $row = badgeRow(
        getJson(CatalogRequest::url('/products', ['locale' => 'en']), ['Authorization' => "Bearer {$key}"])->assertOk()->json('data'),
        'unlabelled',
    );

    // Present and null — a missing key and a null mean different things to a
    // client that merges payloads.
    expect($row)->toHaveKey('badge')
        ->and($row['badge'])->toBeNull();
})->group('fast');

it('sends the badge on the product detail', function (): void {
    [, $key] = badgeCatalog();

    getJson(CatalogRequest::url('/products/labelled', ['locale' => 'en']), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('data.badge', 'Popular');
})->group('fast');

it('treats a badge of only spaces as no badge', function (): void {
    $tenant = Tenant::factory()->create(['default_locale' => 'el']);
    [, $key] = CatalogRequest::key($tenant);

    Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create([
        'slug' => 'blank-label',
        'badge' => ['el' => '   ', 'en' => '   '],
    ]));

    getJson(CatalogRequest::url('/products/blank-label', ['locale' => 'el']), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('data.badge', null);
})->group('fast');

it('sends the badge unresolved in the sync feed, and resolved in its product', function (): void {
    [, $key] = badgeCatalog(ApiKeyType::Secret);

    $rows = getJson(CatalogRequest::url('/sync/products'), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->json('data');

    $labelled = badgeRow($rows, 'labelled');
    $unlabelled = badgeRow($rows, 'unlabelled');

    expect($labelled['translations']['el']['badge'])->toBe('Δημοφιλές')
        ->and($labelled['translations']['en']['badge'])->toBe('Popular')
        // Resolved in the same locale as the rest of the resolved product.
        ->and($labelled['product']['badge'])->toBe($labelled['product']['title'] === 'Ελληνικός τίτλος' ? 'Δημοφιλές' : 'Popular')
        ->and($unlabelled['translations']['el'])->toHaveKey('badge')
        ->and($unlabelled['translations']['el']['badge'])->toBeNull()
        ->and($unlabelled['product']['badge'])->toBeNull();
})->group('fast');
