<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeader;

/*
 * `GET /api/v1/health` — the first real route under `/api/v1`, and the one that
 * proves the group is wired: prefix, key authentication, tenant resolution and
 * the JSON error envelope all run on it before any controller does.
 */

function healthKey(ApiKeyType $type = ApiKeyType::Publishable): string
{
    $tenant = Tenant::factory()->create();

    return Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Health probe',
        type: $type,
        scopes: ApiScope::readScopes(),
    )->plainTextKey);
}

it('returns the API version for a valid publishable key', function (): void {
    withHeader('X-Kaiki-Key', healthKey())
        ->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson(['data' => ['status' => 'ok', 'version' => 'v1']]);
})->group('fast', 'api-docs');

it('accepts a secret key too', function (): void {
    withHeader('Authorization', 'Bearer ' . healthKey(ApiKeyType::Secret))
        ->getJson('/api/v1/health')
        ->assertOk();
})->group('fast', 'api-docs');

it('refuses a request with no key', function (): void {
    getJson('/api/v1/health')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'missing_key');
})->group('fast', 'api-docs');

it('names no tenant in the response', function (): void {
    // A key already implies its tenant, so echoing a slug or uuid here would
    // make the cheapest endpoint in the API a tenant-enumeration oracle.
    $tenant = Tenant::factory()->create(['slug' => 'aegean-blue']);

    $key = Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Health probe',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    )->plainTextKey);

    $body = withHeader('X-Kaiki-Key', $key)->getJson('/api/v1/health')->getContent();

    // Not a substring search for the integer id: it is `1` under
    // `RefreshDatabase`, and `"version":"v1"` contains a `1`. Asserting the
    // whole body instead is both stricter and honest — anything added to this
    // response later has to be added here too, deliberately.
    expect($body)->not->toContain('aegean-blue')
        ->and($body)->not->toContain((string) $tenant->uuid)
        ->and(json_decode((string) $body, true))
        ->toBe(['data' => ['status' => 'ok', 'version' => 'v1']]);
})->group('fast', 'api-docs');

it('is served under the versioned prefix and nowhere else', function (): void {
    // `/health` without the version would become a second, unversioned surface
    // the moment someone links it.
    withHeader('X-Kaiki-Key', healthKey())->getJson('/health')->assertNotFound();
})->group('fast', 'api-docs');
