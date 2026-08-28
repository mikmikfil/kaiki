<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Domain\Tenancy\Actions\RevokeApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeader;
use function Pest\Laravel\withHeaders;

/*
 * Pest's function API is used throughout rather than `$this->…`. PHPStan cannot
 * type `$this` inside a Pest closure, and the alternative — excluding tests/
 * from static analysis — would take the gate off the code most likely to lie.
 */

beforeEach(function (): void {
    // Two routes standing in for the real API, which arrives in #35. They exist
    // so the middleware is exercised through the actual HTTP stack rather than
    // called directly — middleware that works in isolation but not on a route
    // is a familiar way to ship a hole.
    Route::middleware('api.key')->get('/_test/read', fn () => response()->json(['tenant' => tenant()?->getKey()]));
    Route::middleware(['api.key', 'api.scope:quotes.write'])->post('/_test/write', fn () => response()->json(['ok' => true]));
});

/**
 * @param  list<ApiScope>  $scopes
 * @param  list<string>  $origins
 */
function makeKey(Tenant $tenant, ApiKeyType $type, array $scopes, array $origins = []): string
{
    return Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Test key',
        type: $type,
        scopes: $scopes,
        allowedOrigins: $origins,
    )->plainTextKey);
}

it('resolves the tenant from a valid publishable key', function (): void {
    $tenant = Tenant::factory()->create();
    $key = makeKey($tenant, ApiKeyType::Publishable, ApiScope::readScopes());

    withHeader('Authorization', "Bearer {$key}")
        ->getJson('/_test/read')
        ->assertOk()
        ->assertJson(['tenant' => $tenant->getKey()]);
})->group('fast');

it('accepts the X-Kaiki-Key header the widget uses', function (): void {
    $tenant = Tenant::factory()->create();
    $key = makeKey($tenant, ApiKeyType::Publishable, ApiScope::readScopes());

    withHeader('X-Kaiki-Key', $key)->getJson('/_test/read')->assertOk();
})->group('fast');

it('rejects a request with no key', function (): void {
    getJson('/_test/read')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'missing_key');
})->group('fast');

it('rejects a malformed key', function (): void {
    withHeader('Authorization', 'Bearer not-a-key')
        ->getJson('/_test/read')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'malformed_key');
})->group('fast');

it('rejects an unknown prefix', function (): void {
    withHeader('Authorization', 'Bearer pk_live_zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz')
        ->getJson('/_test/read')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_key');
})->group('fast');

it('rejects a right prefix with the wrong secret', function (): void {
    // The dangerous near miss: someone who read a prefix out of a page source
    // must get no further than someone guessing at random.
    $tenant = Tenant::factory()->create();
    $key = makeKey($tenant, ApiKeyType::Publishable, ApiScope::readScopes());
    $tampered = substr($key, 0, 14) . str_repeat('x', strlen($key) - 14);

    withHeader('Authorization', "Bearer {$tampered}")
        ->getJson('/_test/read')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_key');
})->group('fast');

it('rejects a revoked key', function (): void {
    $tenant = Tenant::factory()->create();
    $key = makeKey($tenant, ApiKeyType::Publishable, ApiScope::readScopes());

    Tenancy::forTenant($tenant, function (): void {
        (new RevokeApiKey)(ApiKey::query()->firstOrFail());
    });

    withHeader('Authorization', "Bearer {$key}")
        ->getJson('/_test/read')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'revoked_key');
})->group('fast');

it('rejects an expired key', function (): void {
    $tenant = Tenant::factory()->create();

    $key = Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Expired',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
        expiresAt: now()->subHour(),
    )->plainTextKey);

    withHeader('Authorization', "Bearer {$key}")
        ->getJson('/_test/read')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'expired_key');
})->group('fast');

it('refuses a publishable key on a route needing a secret-only scope', function (): void {
    // SEC-5: the refusal happens in middleware, before any controller runs.
    $tenant = Tenant::factory()->create();
    $key = makeKey($tenant, ApiKeyType::Publishable, ApiScope::readScopes());

    withHeader('Authorization', "Bearer {$key}")
        ->postJson('/_test/write')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope')
        ->assertJsonPath('error.details.required_scope', 'quotes.write');
})->group('fast');

it('allows a secret key holding the scope', function (): void {
    $tenant = Tenant::factory()->create();
    $key = makeKey($tenant, ApiKeyType::Secret, [ApiScope::QuotesWrite]);

    withHeader('Authorization', "Bearer {$key}")
        ->postJson('/_test/write')
        ->assertOk();
})->group('fast');

it('rejects a secret key that arrives with an Origin header', function (): void {
    // SEC-5(3). Browsers always send Origin cross-origin, so this is exactly
    // what a secret key leaking into front-end code looks like on the wire.
    $tenant = Tenant::factory()->create();
    $key = makeKey($tenant, ApiKeyType::Secret, [ApiScope::QuotesWrite]);

    withHeaders(['Authorization' => "Bearer {$key}", 'Origin' => 'https://operator.example'])
        ->getJson('/_test/read')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'secret_key_from_browser');
})->group('fast');

it('enforces the origin allow-list on a publishable key', function (): void {
    $tenant = Tenant::factory()->create();
    $key = makeKey($tenant, ApiKeyType::Publishable, ApiScope::readScopes(), ['https://operator.example']);

    withHeaders(['Authorization' => "Bearer {$key}", 'Origin' => 'https://operator.example'])
        ->getJson('/_test/read')->assertOk();

    withHeaders(['Authorization' => "Bearer {$key}", 'Origin' => 'https://evil.example'])
        ->getJson('/_test/read')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'origin_not_allowed');
})->group('fast');

it('allows any origin when the allow-list is empty', function (): void {
    $tenant = Tenant::factory()->create();
    $key = makeKey($tenant, ApiKeyType::Publishable, ApiScope::readScopes());

    withHeaders(['Authorization' => "Bearer {$key}", 'Origin' => 'https://anywhere.example'])
        ->getJson('/_test/read')->assertOk();
})->group('fast');

it('returns rejection messages in both Greek and English', function (): void {
    // CNV-11: no literals, no exception text. The widget renders in Greek and
    // must not need a second request to find out what went wrong.
    $response = getJson('/_test/read')->assertStatus(401);

    expect($response->json('error.message'))->toContain('No API key')
        ->and($response->json('error.message_el'))->toContain('κλειδί API')
        ->and($response->json('error.message'))->not->toBe($response->json('error.message_el'));
})->group('fast');

it('verifies a key in a single indexed lookup', function (): void {
    $tenant = Tenant::factory()->create();
    $key = makeKey($tenant, ApiKeyType::Publishable, ApiScope::readScopes());

    DB::enableQueryLog();
    withHeader('Authorization', "Bearer {$key}")->getJson('/_test/read')->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $lookups = array_filter(
        $queries,
        static fn (array $q): bool => str_contains((string) $q['query'], 'api_keys')
            && str_starts_with(trim((string) $q['query']), 'select'),
    );

    // Authentication runs on every public API request, so an extra read here
    // multiplies across the whole API — and NFR-1 gives availability 150 ms p95.
    expect($lookups)->toHaveCount(1)
        ->and((string) reset($lookups)['query'])->toContain('prefix');
})->group('fast');

it('resolves the owning tenant, not whichever was resolved before', function (): void {
    $other = Tenant::factory()->create();
    $key = makeKey($other, ApiKeyType::Publishable, ApiScope::readScopes());

    withHeader('Authorization', "Bearer {$key}")
        ->getJson('/_test/read')
        ->assertOk()
        ->assertJson(['tenant' => $other->getKey()]);
})->group('fast');
