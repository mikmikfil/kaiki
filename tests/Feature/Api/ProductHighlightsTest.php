<?php

declare(strict_types=1);

use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\getJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| `highlights` and the itinerary's `time` (2026-09-16)
|--------------------------------------------------------------------------
|
| «Τι θα ζήσετε»: a translatable list of short lines, optional, with **exactly
| the shape of `includes`** — an array of strings in the negotiated locale on the
| detail, `null` when the operator filled nothing in, and the unresolved
| `{"el": [...], "en": [...]}` in the sync feed. The WordPress plugin maps it into
| its highlights field with the code it already has for `includes`.
|
| And each itinerary stop gains an optional `time` («09:45»), always present on
| the stop, null when the stop has no fixed hour.
|
*/

/** @return array{0: Tenant, 1: string} */
function highlightsCatalog(ApiKeyType $type = ApiKeyType::Publishable): array
{
    $tenant = Tenant::factory()->create(['default_locale' => 'el']);

    [, $key] = CatalogRequest::key($tenant, $type, [ApiScope::ProductsRead]);

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create([
            'slug' => 'full',
            'sort_order' => 1,
            'title' => ['el' => 'Ελληνικός τίτλος', 'en' => 'English title'],
            'highlights' => [
                'el' => ['Τρεις στάσεις για μπάνιο', 'Μάσκες για όλους'],
                'en' => ['Three swimming stops', 'Masks for everyone'],
            ],
            'includes' => ['el' => ['Νερό'], 'en' => ['Water']],
            'itinerary_stops' => [
                'el' => [
                    ['key' => 's1', 'time' => '09:00', 'name' => 'Επιβίβαση'],
                    ['key' => 's2', 'name' => 'Μπάνιο όπου θέλετε'],
                ],
                'en' => [
                    ['key' => 's1', 'time' => '09:00', 'name' => 'Boarding'],
                    ['key' => 's2', 'name' => 'A swim wherever you like'],
                ],
                '_geo' => ['s1' => ['lat' => 37.9339, 'lng' => 23.6512]],
            ],
        ]);

        Product::factory()->create(['slug' => 'bare', 'sort_order' => 2]);
    });

    return [$tenant, $key];
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function highlightsRow(array $rows, string $slug): array
{
    foreach ($rows as $row) {
        if (($row['slug'] ?? null) === $slug) {
            return $row;
        }
    }

    throw new RuntimeException("No row for {$slug}.");
}

it('sends highlights on the detail in the requested locale, shaped like includes', function (string $locale, array $expected, array $includes): void {
    [, $key] = highlightsCatalog();

    $data = getJson(CatalogRequest::url('/products/full', ['locale' => $locale]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->json('data');

    expect($data['highlights'])->toBe($expected)
        ->and($data['includes'])->toBe($includes);
})->with([
    'greek' => ['el', ['Τρεις στάσεις για μπάνιο', 'Μάσκες για όλους'], ['Νερό']],
    'english' => ['en', ['Three swimming stops', 'Masks for everyone'], ['Water']],
])->group('fast');

it('sends null highlights for a trip without them, as it does includes', function (): void {
    [, $key] = highlightsCatalog();

    $data = getJson(CatalogRequest::url('/products/bare', ['locale' => 'el']), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKey('highlights')
        ->and($data['highlights'])->toBeNull()
        ->and($data['includes'])->toBeNull()
        ->and($data['itinerary_stops'])->toBe([]);
})->group('fast');

it('keeps highlights off the product list, where includes is not sent either', function (): void {
    [, $key] = highlightsCatalog();

    $row = highlightsRow(
        getJson(CatalogRequest::url('/products', ['locale' => 'el']), ['Authorization' => "Bearer {$key}"])->assertOk()->json('data'),
        'full',
    );

    expect($row)->not->toHaveKey('highlights')->and($row)->not->toHaveKey('includes');
})->group('fast');

it('sends each stop with its time, null when it has none, beside its coordinates', function (): void {
    [, $key] = highlightsCatalog();

    $stops = getJson(CatalogRequest::url('/products/full', ['locale' => 'en']), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->json('data.itinerary_stops');

    expect(array_keys($stops[0]))->toBe(['key', 'time', 'name', 'description', 'duration_minutes', 'lat', 'lng'])
        ->and($stops[0]['time'])->toBe('09:00')
        ->and($stops[0]['name'])->toBe('Boarding')
        ->and($stops[0]['lat'])->toBe(37.9339)
        ->and($stops[1]['time'])->toBeNull()
        ->and($stops[1]['lat'])->toBeNull();
})->group('fast');

it('sends highlights unresolved in the sync feed, and resolved in its product', function (): void {
    [, $key] = highlightsCatalog(ApiKeyType::Secret);

    $rows = getJson(CatalogRequest::url('/sync/products'), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->json('data');

    $full = highlightsRow($rows, 'full');
    $bare = highlightsRow($rows, 'bare');

    expect($full['translations']['el']['highlights'])->toBe(['Τρεις στάσεις για μπάνιο', 'Μάσκες για όλους'])
        ->and($full['translations']['en']['highlights'])->toBe(['Three swimming stops', 'Masks for everyone'])
        ->and($full['product']['highlights'])->toBeArray()
        ->and($bare['translations']['el'])->toHaveKey('highlights')
        ->and($bare['translations']['el']['highlights'])->toBeNull()
        ->and($bare['product']['highlights'])->toBeNull();
})->group('fast');
