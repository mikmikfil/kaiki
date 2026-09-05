<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\getJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| Locale resolution on the catalogue reads — spec I18N-5, ADR-0008
|--------------------------------------------------------------------------
|
| `docs/api.md` §3.1 states the API's half of the chain as three steps:
| `?locale=`, then `Accept-Language`, then the tenant's `default_locale` — with
| `en` behind all of them. Each step is asserted here on its own, because a
| chain tested only end to end passes when two steps are wired to the same
| signal.
|
| **`?locale=` was not implemented before this issue.** #35 shipped `/branding`
| against the same middleware, and the contract has listed `LocaleQuery` on every
| operation since the document was written — the query key in the code was
| `?lang=`, the panel's spelling, which no API client sends. Both keys now reach
| step 1 of `LocaleResolver`, which ADR-0008 requires be the only implementation
| of the order.
|
| The **400** is the other half, and it is deliberately asymmetric: a stray
| `?lang=fr` on a public page falls through, and an explicit `?locale=fr` on the
| API is an error. §3.1 gives the reason — "a typo must not silently serve Greek
| to a French page", and a machine client has no way to notice that it did.
|
*/

/**
 * A tenant whose default is Greek, holding one product translated both ways.
 *
 * @return array{0: Tenant, 1: string, 2: Product}
 */
function localeCatalog(string $defaultLocale = 'el'): array
{
    $tenant = Tenant::factory()->create(['default_locale' => $defaultLocale]);

    [, $key] = CatalogRequest::key($tenant);

    $product = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create([
        'title' => ['el' => 'Ηλιοβασίλεμα στην Αίγινα', 'en' => 'Sunset in Aegina'],
    ]));

    return [$tenant, $key, $product];
}

it('honours an explicit locale above everything else', function (): void {
    [, $key] = localeCatalog();

    // Step 1. `Accept-Language` says Greek and the tenant default is Greek;
    // only the explicit override can produce English here, so this asserts the
    // step rather than a coincidence.
    getJson(
        CatalogRequest::url('/products', ['locale' => 'en']),
        ['Authorization' => "Bearer {$key}", 'Accept-Language' => 'el-GR,el;q=0.9'],
    )
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Sunset in Aegina')
        ->assertHeader('Content-Language', 'en');
})->group('fast');

it('falls back to Accept-Language when no locale is given', function (): void {
    [, $key] = localeCatalog();

    // Step 2. Quality values parsed, matched against the supported set.
    getJson(
        CatalogRequest::url('/products'),
        ['Authorization' => "Bearer {$key}", 'Accept-Language' => 'en-GB,en;q=0.9,el;q=0.8'],
    )
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Sunset in Aegina');
})->group('fast');

it('falls back to the tenant default when nothing is requested', function (): void {
    [, $key] = localeCatalog();

    // Step 3. No `?locale=`, and no usable `Accept-Language`.
    //
    // The empty header is not decoration. Symfony's `Request::create()` —
    // which every Laravel test request goes through —
    // defaults `HTTP_ACCEPT_LANGUAGE` to `en-us,en;q=0.5`, so a test that
    // simply omits the header is silently testing step 2 in English and would
    // pass with the tenant default never consulted at all.
    getJson(CatalogRequest::url('/products'), [
        'Authorization' => "Bearer {$key}",
        'Accept-Language' => '',
    ])
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Ηλιοβασίλεμα στην Αίγινα')
        ->assertHeader('Content-Language', 'el');
})->group('fast');

it('treats an unmatched Accept-Language as no preference rather than an error', function (): void {
    [, $key] = localeCatalog();

    // §3.1 is explicit that this is **not** an error — it falls through to the
    // tenant default. Only the explicit `?locale=` refuses.
    getJson(CatalogRequest::url('/products'), [
        'Authorization' => "Bearer {$key}",
        'Accept-Language' => 'fr-FR,fr;q=0.9',
    ])
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Ηλιοβασίλεμα στην Αίγινα');
})->group('fast');

it('refuses an unsupported explicit locale', function (): void {
    [, $key] = localeCatalog();

    $response = getJson(
        CatalogRequest::url('/products', ['locale' => 'fr']),
        ['Authorization' => "Bearer {$key}"],
    );

    $response->assertStatus(400)
        ->assertJsonPath('error.code', 'unsupported_locale')
        ->assertJsonPath('error.details.requested', 'fr')
        ->assertJsonPath('error.details.supported', ['el', 'en']);

    // §4.1: both languages in every error, always.
    expect($response->json('error.message_el'))->not->toBeEmpty();
})->group('fast');

it('narrows the supported set to what the tenant sells in', function (): void {
    $tenant = Tenant::factory()->create([
        'default_locale' => 'el',
        'supported_locales' => ['el'],
    ]);

    [, $key] = CatalogRequest::key($tenant);

    Tenancy::forTenant($tenant, fn () => Product::factory()->create());

    // An operator selling only in Greek must not have their page
    // half-translated by a widget that asked for English.
    getJson(CatalogRequest::url('/products', ['locale' => 'en']), ['Authorization' => "Bearer {$key}"])
        ->assertStatus(400)
        ->assertJsonPath('error.details.supported', ['el']);
})->group('fast');

it('resolves the detail payload in the requested locale too', function (): void {
    [, $key, $product] = localeCatalog();

    getJson(
        CatalogRequest::url("/products/{$product->uuid}", ['locale' => 'en']),
        ['Authorization' => "Bearer {$key}"],
    )
        ->assertOk()
        ->assertJsonPath('data.title', 'Sunset in Aegina')
        ->assertHeader('Content-Language', 'en');
})->group('fast');

it('varies on the headers a shared cache would otherwise ignore', function (): void {
    [, $key] = localeCatalog();

    $response = getJson(CatalogRequest::url('/products'), ['Authorization' => "Bearer {$key}"]);

    $vary = array_map(trim(...), explode(',', (string) $response->headers->get('Vary')));

    // §3.1. Without these a CDN serves one operator's Greek payload to another
    // operator's English page, and nothing reproduces locally.
    expect($vary)->toContain('Accept-Language')
        ->and($vary)->toContain('Origin')
        ->and($vary)->toContain('X-Kaiki-Key');
})->group('fast');
