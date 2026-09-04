<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| GET /api/v1/branding — spec BRD-2, BRD-6, BRD-8, WGT-9
|--------------------------------------------------------------------------
|
| The widget's first call on every page load, and the first real read endpoint
| in the product. Two things are load-bearing:
|
| - **`custom_css` is never in this payload.** BRD-2's reason is worth repeating
|   where somebody might add it: injecting operator CSS into someone else's site
|   is not ours to do. It is `null` rather than absent, because a widget that
|   branches on presence breaks the day the hosted page sends one.
| - **The ETag is computed from the payload**, so it cannot disagree with the
|   body. A stamp taken from `updated_at` would look right and drift the moment
|   the locale or the test flag changed the bytes without touching the row.
|
*/

/**
 * @param  list<ApiScope>  $scopes
 * @param  list<string>  $allowedOrigins
 * @return array{0: Tenant, 1: string} the tenant and a usable key
 */
function brandingKey(
    ApiKeyType $type = ApiKeyType::Publishable,
    array $scopes = [ApiScope::BrandingRead],
    ApiKeyEnvironment $environment = ApiKeyEnvironment::Live,
    array $allowedOrigins = [],
): array {
    $tenant = Tenant::factory()->create();

    $plain = Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Widget',
        type: $type,
        scopes: $scopes,
        environment: $environment,
        allowedOrigins: $allowedOrigins,
    )->plainTextKey);

    return [$tenant, $plain];
}

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function getBranding(string $key, array $headers = []): TestResponse
{
    return getJson('/api/v1/branding', array_merge([
        'Authorization' => "Bearer {$key}",
    ], $headers));
}

it('BRD-6: returns the brand payload for a valid key', function (): void {
    [, $key] = brandingKey();

    $response = getBranding($key);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'tenant' => ['uuid', 'name', 'slug', 'timezone', 'default_locale', 'currency'],
                'logo' => ['light_url', 'dark_url', 'favicon_url'],
                'colors' => ['primary', 'secondary', 'accent', 'background', 'text'],
                'font' => ['family', 'source', 'css_url'],
                'button_radius_px',
                'widget_theme',
                'css_variables',
                'is_test',
            ],
        ]);
})->group('fast');

it('BRD-2: never returns custom_css, even when the operator has set one', function (): void {
    [$tenant, $key] = brandingKey();

    Tenancy::forTenant($tenant, function (): void {
        BrandProfile::query()->firstOrFail()
            ->forceFill(['custom_css' => '.kaiki { color: red }'])->saveQuietly();
    });

    // Null rather than absent: the contract lists the key, and a widget that
    // branches on presence breaks when the hosted page starts sending one.
    getBranding($key)->assertOk()->assertJsonPath('data.custom_css', null);
})->group('fast');

it('WGT-9: hands the widget ready-made custom properties', function (): void {
    [, $key] = brandingKey();

    $response = getBranding($key)->assertOk();

    // The promise is that the widget never hardcodes a colour, and the way to
    // keep that true is to give it none it did not read from here.
    expect($response->json('data.css_variables'))
        ->toHaveKeys(['--kaiki-primary', '--kaiki-radius', '--kaiki-font-family'])
        ->and($response->json('data.css_variables.--kaiki-primary'))
        ->toBe($response->json('data.colors.primary'));
})->group('fast');

it('BRD-6: carries a strong ETag and a cache directive', function (): void {
    [, $key] = brandingKey();

    $response = getBranding($key)->assertOk();

    expect($response->headers->get('ETag'))->toStartWith('"')
        // 60 from `docs/api.md` §3.6, which is the authority over the issue.
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=60')
        ->and($response->headers->get('Cache-Control'))->toContain('public');
})->group('fast');

it('BRD-6: answers a matching conditional request with 304 and no body', function (): void {
    [, $key] = brandingKey();

    $etag = getBranding($key)->assertOk()->headers->get('ETag');

    $second = getBranding($key, ['If-None-Match' => $etag]);

    $second->assertStatus(304);

    expect($second->getContent())->toBe('')
        ->and($second->headers->get('ETag'))->toBe($etag);
})->group('fast');

it('BRD-6: handles a weak validator and a list of tags', function (): void {
    // Both arrive in the wild. Comparing the raw header would miss them and
    // turn every conditional request into a full response — a failure nobody
    // notices, because everything still works.
    [, $key] = brandingKey();

    $etag = getBranding($key)->assertOk()->headers->get('ETag');

    getBranding($key, ['If-None-Match' => 'W/' . $etag])->assertStatus(304);
    getBranding($key, ['If-None-Match' => '"other", ' . $etag])->assertStatus(304);
    getBranding($key, ['If-None-Match' => '"stale"'])->assertOk();
})->group('fast');

it('BRD-8: serves changed branding with a new ETag once the cache expires', function (): void {
    [$tenant, $key] = brandingKey();

    $first = getBranding($key)->assertOk();

    Tenancy::forTenant($tenant, function (): void {
        BrandProfile::query()->firstOrFail()
            ->forceFill(['color_primary' => '#123456'])->saveQuietly();
    });

    // The TTL is what BRD-8 buys — no widget rebuild — so the test expires the
    // cache rather than sleeping through it.
    Cache::flush();

    $second = getBranding($key)->assertOk();

    expect($second->json('data.colors.primary'))->toBe('#123456')
        ->and($second->headers->get('ETag'))->not->toBe($first->headers->get('ETag'));
})->group('fast');

it('refuses a request with no key at all', function (): void {
    getJson('/api/v1/branding')->assertUnauthorized()
        ->assertJsonStructure(['error' => ['code', 'message', 'message_el']]);
})->group('fast');

it('refuses an invalid key with the shared envelope', function (): void {
    getBranding('pk_live_notarealkeyatall')->assertUnauthorized()
        ->assertJsonStructure(['error' => ['code', 'message', 'message_el']]);
})->group('fast');

it('ADR-0013: accepts a secret key for a read', function (): void {
    // A secret key is strictly wider than a publishable one, so refusing it for
    // a read would make a server-to-server integration carry two keys.
    [, $key] = brandingKey(type: ApiKeyType::Secret);

    getBranding($key)->assertOk();
})->group('fast');

it('SEC-5: refuses a key without the branding scope', function (): void {
    // Refused by middleware before the controller loads, so a narrowed key is
    // turned away without an endpoint having to remember to check.
    [, $key] = brandingKey(scopes: [ApiScope::ProductsRead]);

    getBranding($key)->assertForbidden();
})->group('fast');

it('CNV-8: exposes no database id and no tenant internals', function (): void {
    [, $key] = brandingKey();

    $body = getBranding($key)->assertOk()->json('data');

    expect($body['tenant'])->not->toHaveKey('id')
        ->and($body['tenant'])->not->toHaveKey('vat_number')
        ->and($body['tenant'])->not->toHaveKey('legal_name')
        ->and($body)->not->toHaveKey('id')
        ->and($body)->not->toHaveKey('tenant_id');
})->group('fast');

it('marks a test key response as such', function (): void {
    // A property of the **key**, not of the tenant — which is why it sits
    // outside the cached payload. Caching it would serve a live widget the
    // sandbox flag.
    [, $live] = brandingKey();
    [, $test] = brandingKey(environment: ApiKeyEnvironment::Test);

    expect(getBranding($live)->json('data.is_test'))->toBeFalse()
        ->and(getBranding($test)->json('data.is_test'))->toBeTrue();
})->group('fast');
