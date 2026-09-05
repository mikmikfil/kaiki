<?php

declare(strict_types=1);

use App\Enums\ApiScope;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;

use function Pest\Laravel\getJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| GET /api/v1/products — spec WGT-5, WGT-6, WGT-13, SEC-2, SAA-7, CNV-8
|--------------------------------------------------------------------------
|
| The widget's `list` mount, the `[kaiki_list]` shortcode and the hosted landing
| page all read this. Four things carry weight:
|
| - **Only `active`.** `draft`, `inactive` and `archived` are equally invisible
|   to a guest; only one of the three is obvious.
| - **An unknown category is an empty list, not an error** (WGT-6). An operator
|   writes `data-category` into their page once and the embed outlives the
|   products it was written for.
| - **A read-only tenant is still served** (SAA-7). A lapsed subscription closes
|   bookings, not the catalogue.
| - **No database id anywhere**, checked recursively rather than at the top
|   level, because an audit that only reads the surface passes the day someone
|   adds a relation.
|
*/

/**
 * Every scalar in a decoded payload, flattened with its dotted path.
 *
 * @param  array<mixed>  $payload
 * @return array<string, mixed>
 */
function flattenPayload(array $payload, string $prefix = ''): array
{
    $flat = [];

    foreach ($payload as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $flat += flattenPayload($value, $path);

            continue;
        }

        $flat[$path] = $value;
    }

    return $flat;
}

it('returns only active products for the resolved tenant', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create(['slug' => 'live-one']);
        Product::factory()->draft()->create(['slug' => 'still-a-draft']);
        Product::factory()->create(['slug' => 'switched-off', 'status' => ProductStatus::Inactive]);
        Product::factory()->create(['slug' => 'gone', 'status' => ProductStatus::Archived]);
    });

    $response = getJson(CatalogRequest::url('/products'), ['Authorization' => "Bearer {$key}"]);

    $response->assertOk();

    expect(array_column($response->json('data'), 'slug'))->toBe(['live-one']);
})->group('fast');

it('never serves another operator catalogue', function (): void {
    [$tenant, $key] = CatalogRequest::key();
    [$other] = CatalogRequest::key();

    Tenancy::forTenant($tenant, fn () => Product::factory()->create(['slug' => 'mine']));
    Tenancy::forTenant($other, fn () => Product::factory()->create(['slug' => 'theirs']));

    $response = getJson(CatalogRequest::url('/products'), ['Authorization' => "Bearer {$key}"]);

    expect(array_column($response->json('data'), 'slug'))->toBe(['mine']);
})->group('fast');

it('orders by featured then sort order then uuid', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create(['slug' => 'third', 'sort_order' => 20, 'is_featured' => false]);
        Product::factory()->create(['slug' => 'second', 'sort_order' => 10, 'is_featured' => false]);
        Product::factory()->create(['slug' => 'first', 'sort_order' => 99, 'is_featured' => true]);
    });

    $response = getJson(CatalogRequest::url('/products'), ['Authorization' => "Bearer {$key}"]);

    // The featured one leads despite the largest `sort_order` — that is the
    // whole point of the flag, and ordering on `sort_order` alone would hide
    // the bug behind a plausible-looking list.
    expect(array_column($response->json('data'), 'slug'))->toBe(['first', 'second', 'third']);
})->group('fast');

it('filters by category and answers an unknown one with an empty list', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create(['slug' => 'sunset-trip', 'category' => ProductCategory::Sunset]);
        Product::factory()->create(['slug' => 'day-trip', 'category' => ProductCategory::SharedFullDay]);
    });

    $filtered = getJson(
        CatalogRequest::url('/products', ['category' => 'sunset']),
        ['Authorization' => "Bearer {$key}"],
    );

    expect(array_column($filtered->json('data'), 'slug'))->toBe(['sunset-trip']);

    // WGT-6. Not a 400, not the whole catalogue — an empty list. Widening to
    // everything is the failure that looks like it works.
    $unknown = getJson(
        CatalogRequest::url('/products', ['category' => 'submarine-tour']),
        ['Authorization' => "Bearer {$key}"],
    );

    $unknown->assertOk();
    expect($unknown->json('data'))->toBe([]);
})->group('fast');

it('accepts several categories at once', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create(['slug' => 'sunset-trip', 'sort_order' => 1, 'category' => ProductCategory::Sunset]);
        Product::factory()->create(['slug' => 'day-trip', 'sort_order' => 2, 'category' => ProductCategory::SharedFullDay]);
        Product::factory()->create(['slug' => 'charter', 'sort_order' => 3, 'category' => ProductCategory::PrivateFullDay]);
    });

    $response = getJson(
        CatalogRequest::url('/products', ['category' => ['sunset', 'shared_full_day']]),
        ['Authorization' => "Bearer {$key}"],
    );

    expect(array_column($response->json('data'), 'slug'))->toBe(['sunset-trip', 'day-trip']);
})->group('fast');

it('filters by mode and by vessel uuid', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    $vesselUuid = Tenancy::forTenant($tenant, function (): string {
        $boat = Vessel::factory()->create();

        Product::factory()->create(['slug' => 'seats', 'vessel_id' => $boat->getKey()]);
        Product::factory()->perVessel()->create(['slug' => 'charter']);

        return $boat->uuid;
    });

    $byMode = getJson(
        CatalogRequest::url('/products', ['mode' => 'per_vessel']),
        ['Authorization' => "Bearer {$key}"],
    );

    expect(array_column($byMode->json('data'), 'slug'))->toBe(['charter']);

    $byVessel = getJson(
        CatalogRequest::url('/products', ['vessel' => $vesselUuid]),
        ['Authorization' => "Bearer {$key}"],
    );

    expect(array_column($byVessel->json('data'), 'slug'))->toBe(['seats']);
})->group('fast');

it('treats another operator vessel uuid as a filter that matches nothing', function (): void {
    [$tenant, $key] = CatalogRequest::key();
    [$other] = CatalogRequest::key();

    Tenancy::forTenant($tenant, fn () => Product::factory()->create(['slug' => 'mine']));

    $foreignUuid = Tenancy::forTenant($other, fn (): string => Vessel::factory()->create()->uuid);

    $response = getJson(
        CatalogRequest::url('/products', ['vessel' => $foreignUuid]),
        ['Authorization' => "Bearer {$key}"],
    );

    // Empty, and 200. A 404 here would confirm the boat exists somewhere,
    // which is the cross-tenant probe SEC-2 is about.
    $response->assertOk();
    expect($response->json('data'))->toBe([]);
})->group('fast');

it('still serves a read-only tenant', function (): void {
    $tenant = Tenant::factory()->readOnly()->create();
    [, $key] = CatalogRequest::key($tenant);

    Tenancy::forTenant($tenant, fn () => Product::factory()->create(['slug' => 'still-listed']));

    // SAA-7. A lapsed subscription closes bookings, not the catalogue — the
    // operator's own website must keep showing their trips while they sort the
    // card out.
    getJson(CatalogRequest::url('/products'), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'still-listed');
})->group('fast');

it('pages with a cursor and never reports a total', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, function (): void {
        foreach (range(1, 5) as $i) {
            Product::factory()->create(['slug' => "trip-{$i}", 'sort_order' => $i]);
        }
    });

    $first = getJson(
        CatalogRequest::url('/products', ['per_page' => 2]),
        ['Authorization' => "Bearer {$key}"],
    );

    $first->assertOk()
        ->assertJsonPath('pagination.per_page', 2)
        ->assertJsonPath('pagination.has_more', true)
        ->assertJsonPath('pagination.prev_cursor', null);

    expect($first->json('pagination'))->not->toHaveKey('total');
    expect(array_column($first->json('data'), 'slug'))->toBe(['trip-1', 'trip-2']);

    $second = getJson(
        (string) $first->json('pagination.next_url'),
        ['Authorization' => "Bearer {$key}"],
    );

    expect(array_column($second->json('data'), 'slug'))->toBe(['trip-3', 'trip-4']);

    $last = getJson(
        CatalogRequest::url('/products', ['per_page' => 2, 'cursor' => $second->json('pagination.next_cursor')]),
        ['Authorization' => "Bearer {$key}"],
    );

    // §3.5: `next_cursor` is null exactly when `has_more` is false.
    $last->assertJsonPath('pagination.has_more', false)
        ->assertJsonPath('pagination.next_cursor', null);
})->group('fast');

it('keeps the filter on the next page url', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, function (): void {
        foreach (range(1, 3) as $i) {
            Product::factory()->create([
                'slug' => "sunset-{$i}",
                'sort_order' => $i,
                'category' => ProductCategory::Sunset,
            ]);
        }
    });

    $response = getJson(
        CatalogRequest::url('/products', ['category' => 'sunset', 'per_page' => 1]),
        ['Authorization' => "Bearer {$key}"],
    );

    // Losing the filter on page two silently widens the traversal to the whole
    // catalogue, and the client has no way to notice.
    expect((string) $response->json('pagination.next_url'))->toContain('category=sunset');
})->group('fast');

it('clamps per_page rather than rejecting it', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, fn () => Product::factory()->create());

    getJson(CatalogRequest::url('/products', ['per_page' => 5000]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('pagination.per_page', 100);

    getJson(CatalogRequest::url('/products', ['per_page' => 0]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('pagination.per_page', 1);
})->group('fast');

it('reads an unusable per_page as no preference rather than an error', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, fn () => Product::factory()->create());

    // 200 and the default, not a 422. The contract lists no 422 among this
    // operation's responses at all, and refusing garbage while clamping an
    // out-of-range number is an inconsistency a caller has to learn.
    getJson(CatalogRequest::url('/products', ['per_page' => 'abc']), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('pagination.per_page', 24);
})->group('fast');

it('refuses a malformed cursor instead of serving page one', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, fn () => Product::factory()->create());

    getJson(CatalogRequest::url('/products', ['cursor' => 'not-a-cursor']), ['Authorization' => "Bearer {$key}"])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_cursor');

    // Over the contract's 512-character cap. Reported as a malformed cursor
    // rather than as a length violation: it is a value that cannot have come
    // from `pagination.next_cursor`, which is what the code means.
    getJson(
        CatalogRequest::url('/products', ['cursor' => str_repeat('a', 600)]),
        ['Authorization' => "Bearer {$key}"],
    )
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_cursor');
})->group('fast');

it('exposes no database id and no financial field beyond the from price', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, fn () => Product::factory()->create(['price_from_cents' => 6500]));

    $response = getJson(CatalogRequest::url('/products'), ['Authorization' => "Bearer {$key}"]);

    $flat = flattenPayload((array) $response->json());

    foreach (array_keys($flat) as $path) {
        $leaf = (string) last(explode('.', $path));

        expect($leaf)->not->toBe('id')
            ->and($leaf)->not->toBe('tenant_id')
            ->and(str_ends_with($leaf, '_id'))->toBeFalse("[{$path}] exposes an internal id");
    }

    // The one money field the contract permits on this payload (CNV-8, WGT-13).
    $money = array_filter(
        array_keys($flat),
        static fn (string $p): bool => str_contains($p, 'cents') || str_contains($p, 'price'),
    );

    expect(array_values(array_unique(array_map(
        static fn (string $p): string => (string) last(explode('.', $p)),
        $money,
    ))))->toBe(['from_price_cents', 'from_price_formatted']);
})->group('fast');

it('omits the price of a quote product rather than showing zero', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, function (): void {
        // A stale figure left behind by a mode change is exactly the case
        // PRC-5 is about: a zero — or a wrong — price on a boat trip is a
        // support incident, not a UI quirk.
        Product::factory()->quote()->create(['slug' => 'bespoke', 'price_from_cents' => 9900]);
    });

    getJson(CatalogRequest::url('/products'), ['Authorization' => "Bearer {$key}"])
        ->assertJsonPath('data.0.from_price_cents', null)
        ->assertJsonPath('data.0.from_price_formatted', null);
})->group('fast');

it('refuses a key without the products.read scope', function (): void {
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::BrandingRead]);

    Tenancy::forTenant($tenant, fn () => Product::factory()->create());

    getJson(CatalogRequest::url('/products'), ['Authorization' => "Bearer {$key}"])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope');
})->group('fast');
