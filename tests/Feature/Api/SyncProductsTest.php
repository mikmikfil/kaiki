<?php

declare(strict_types=1);

use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeader;
use function Pest\Laravel\withHeaders;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| GET /api/v1/sync/products (WPP-3, WPP-6, SEC-5; docs/api.md §5)
|--------------------------------------------------------------------------
|
| The server-to-server catalogue feed, and the only endpoint in v1 that a
| publishable key cannot reach. Four things are load-bearing and each of them
| fails silently if it is wrong:
|
| - **A publishable key is refused.** The payload is drafts, archived trips and
|   internal SEO fields, and a `pk_` sits in the source of somebody's home page.
| - **Tombstones travel.** A deleted product that simply vanishes from the feed
|   leaves the mirror's page published and indexed for ever — there is no way to
|   tell "deleted" from "not on this page".
| - **Translations are unresolved.** The plugin creates one post per WPML
|   language and needs `el` and `en` in the same response. This is the only
|   endpoint where the raw shape crosses the boundary (§3.1).
| - **`meta.sync_cursor` is the newest row sent, not `now()`.** A cursor of
|   `now()` steps over anything saved while the response was being written, and
|   the mirror never sees that edit again.
|
*/

/** @return array{0: Tenant, 1: string} */
function syncKey(): array
{
    return CatalogRequest::key(type: ApiKeyType::Secret, scopes: [ApiScope::ProductsRead]);
}

it('refuses a publishable key, whatever its scopes', function (): void {
    [, $key] = CatalogRequest::key(type: ApiKeyType::Publishable, scopes: ApiScope::readScopes());

    withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'secret_key_required');
})->group('fast');

it('refuses a secret key that arrives with an Origin, before it reaches the feed', function (): void {
    // SEC-5(3): a browser always sends Origin cross-origin, so this key has been
    // put somewhere it must never be. The refusal is the point.
    [, $key] = syncKey();

    withHeaders(['Authorization' => "Bearer {$key}", 'Origin' => 'https://example.gr'])
        ->getJson(CatalogRequest::url('/sync/products'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'secret_key_in_browser');
})->group('fast');

it('refuses a secret key without the products.read scope', function (): void {
    [, $key] = CatalogRequest::key(type: ApiKeyType::Secret, scopes: [ApiScope::BookingsWrite]);

    withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope');
})->group('fast');

it('returns products a guest may never see', function (): void {
    [$tenant, $key] = syncKey();

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create(['status' => ProductStatus::Active, 'slug' => 'live-one']);
        Product::factory()->create(['status' => ProductStatus::Draft, 'slug' => 'draft-one']);
        Product::factory()->create(['status' => ProductStatus::Inactive, 'slug' => 'off-one']);
        Product::factory()->create(['status' => ProductStatus::Archived, 'slug' => 'gone-one']);
    });

    $body = withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products'))
        ->assertOk()
        ->json();

    expect(array_column($body['data'], 'slug'))
        ->toContain('live-one', 'draft-one', 'off-one', 'gone-one');

    // And the public endpoint still shows exactly one of them, which is the
    // half of this that would otherwise never be checked.
    [, $public] = CatalogRequest::key(tenant: $tenant, type: ApiKeyType::Publishable);

    $list = withHeader('Authorization', "Bearer {$public}")
        ->getJson(CatalogRequest::url('/products'))
        ->assertOk()
        ->json('data');

    expect(array_column($list, 'slug'))->toBe(['live-one']);
})->group('fast');

it('sends a deleted product as a tombstone carrying nothing else', function (): void {
    [$tenant, $key] = syncKey();

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create(['slug' => 'deleted-one'])->delete();
    });

    $row = withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products'))
        ->assertOk()
        ->json('data.0');

    expect($row['tombstone'])->toBeTrue()
        ->and($row['deleted_at'])->not->toBeNull()
        // "only the fields needed to unpublish the CPT entry" (§5). A mirror
        // that can read a title off a tombstone has a reason to keep the page.
        ->and($row)->not->toHaveKey('translations')
        ->and($row)->not->toHaveKey('product');
})->group('fast');

it('returns translations unresolved, both locales at once', function (): void {
    [$tenant, $key] = syncKey();

    Tenancy::forTenant($tenant, function (): void {
        $product = Product::factory()->create(['slug' => 'both-languages']);
        $product->setTranslations('title', ['el' => 'Ελληνικά', 'en' => 'English']);
        $product->save();
    });

    $row = withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products'))
        ->assertOk()
        ->json('data.0');

    expect($row['translations']['el']['title'])->toBe('Ελληνικά')
        ->and($row['translations']['en']['title'])->toBe('English')
        // The resolved payload is there too, for the facts that have no
        // language — duration, capacity, the boat.
        ->and($row['product']['duration_minutes'])->toBeInt();
})->group('fast');

it('gives an unchanged product an unchanged content hash, and a changed one a new hash', function (): void {
    [$tenant, $key] = syncKey();

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create(['slug' => 'hash-me']);
    });

    $first = withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products'))->json('data.0.content_hash');

    // A field that is not translatable content must not move the hash: the
    // mirror uses it to skip a post rewrite, and a hash that changes on every
    // sort-order tweak reshuffles the operator's sitemap for nothing.
    Tenancy::forTenant($tenant, function (): void {
        Product::query()->where('slug', 'hash-me')->firstOrFail()->update(['sort_order' => 99]);
    });

    $second = withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products'))->json('data.0.content_hash');

    expect($second)->toBe($first);

    Tenancy::forTenant($tenant, function (): void {
        $product = Product::query()->where('slug', 'hash-me')->firstOrFail();
        $product->setTranslations('summary', ['el' => 'Νέο', 'en' => 'New']);
        $product->save();
    });

    $third = withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products'))->json('data.0.content_hash');

    expect($third)->not->toBe($first);
})->group('fast');

it('resumes from meta.sync_cursor without losing the row that shares its timestamp', function (): void {
    [$tenant, $key] = syncKey();

    Tenancy::forTenant($tenant, function (): void {
        // Two rows stamped identically, which is what a bulk update produces
        // and what an exclusive `>` boundary would silently drop.
        $at = now()->subDay();

        foreach (['twin-a', 'twin-b'] as $slug) {
            Product::factory()->create(['slug' => $slug])->forceFill(['updated_at' => $at])->saveQuietly();
        }
    });

    $body = withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products'))
        ->assertOk()
        ->json();

    $cursor = $body['meta']['sync_cursor'];

    expect($cursor)->not->toBeNull();

    $again = withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products', ['updated_since' => $cursor]))
        ->assertOk()
        ->json('data');

    expect(array_column($again, 'slug'))->toContain('twin-a', 'twin-b');
})->group('fast');

it('keeps the caller cursor when nothing changed', function (): void {
    [, $key] = syncKey();

    $since = now()->addYear()->toIso8601ZuluString();

    $meta = withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products', ['updated_since' => $since]))
        ->assertOk()
        ->json('meta');

    expect($meta['sync_cursor'])->toBe($since);
})->group('fast');

it('refuses an updated_since it cannot parse rather than guessing', function (): void {
    // The two possible guesses are both wrong in a way the caller cannot see:
    // "absent" re-sends the catalogue every run, "now" skips everything since
    // the last success — a mirror that quietly stops updating.
    [, $key] = syncKey();

    withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products', ['updated_since' => 'last-tuesday-ish']))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_updated_since');
})->group('fast');

it('refuses a malformed cursor rather than serving page one', function (): void {
    [, $key] = syncKey();

    withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products', ['cursor' => 'not-a-cursor']))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'invalid_cursor');
})->group('fast');

it('pages with a cursor and never repeats or skips a row', function (): void {
    [$tenant, $key] = syncKey();

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->count(5)->create();
    });

    $seen = [];
    $url = CatalogRequest::url('/sync/products', ['per_page' => 2]);

    for ($i = 0; $i < 10; $i++) {
        $body = withHeader('Authorization', "Bearer {$key}")->getJson($url)->assertOk()->json();

        $seen = [...$seen, ...array_column($body['data'], 'uuid')];

        if (! $body['pagination']['has_more']) {
            break;
        }

        $url = CatalogRequest::url('/sync/products', [
            'per_page' => 2,
            'cursor' => $body['pagination']['next_cursor'],
        ]);
    }

    expect($seen)->toHaveCount(5)->and(array_unique($seen))->toHaveCount(5);
})->group('fast');

it('narrows to active products and tombstones when asked, because a tombstone is not optional', function (): void {
    [$tenant, $key] = syncKey();

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create(['status' => ProductStatus::Active, 'slug' => 'keep-me']);
        Product::factory()->create(['status' => ProductStatus::Draft, 'slug' => 'drop-me']);
        Product::factory()->create(['slug' => 'unpublish-me'])->delete();
    });

    $slugs = array_column(
        withHeader('Authorization', "Bearer {$key}")
            ->getJson(CatalogRequest::url('/sync/products', ['include_inactive' => 'false']))
            ->assertOk()
            ->json('data'),
        'slug',
    );

    expect($slugs)->toContain('keep-me', 'unpublish-me');
    expect($slugs)->not->toContain('drop-me');
})->group('fast');

it('never caches the feed in a shared cache, and still answers a conditional request', function (): void {
    [$tenant, $key] = syncKey();

    Tenancy::forTenant($tenant, function (): void {
        Product::factory()->create();
    });

    $first = withHeader('Authorization', "Bearer {$key}")
        ->getJson(CatalogRequest::url('/sync/products'))
        ->assertOk();

    expect($first->headers->get('Cache-Control'))->toContain('no-store');

    $etag = $first->headers->get('ETag');

    expect($etag)->not->toBeNull();

    withHeaders(['Authorization' => "Bearer {$key}", 'If-None-Match' => (string) $etag])
        ->getJson(CatalogRequest::url('/sync/products'))
        ->assertStatus(304);
})->group('fast');

it('shows one tenant nothing of another', function (): void {
    [, $key] = syncKey();
    [$other] = syncKey();

    Tenancy::forTenant($other, function (): void {
        Product::factory()->create(['slug' => 'somebody-elses']);
    });

    $slugs = array_column(
        withHeader('Authorization', "Bearer {$key}")
            ->getJson(CatalogRequest::url('/sync/products'))->assertOk()->json('data'),
        'slug',
    );

    expect($slugs)->not->toContain('somebody-elses');
})->group('fast');

it('has no route without a key at all', function (): void {
    getJson(CatalogRequest::url('/sync/products'))->assertStatus(401);
})->group('fast');
