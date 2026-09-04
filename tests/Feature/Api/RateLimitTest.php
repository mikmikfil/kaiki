<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| SEC-6 rate limits — docs/api.md §3.6
|--------------------------------------------------------------------------
|
| Two buckets at once on every class. The **per key** limit protects the
| platform from one operator; the **per IP** limit protects that operator from
| one visitor — and only the IP tells them apart, because a scraper walking an
| operator's whole calendar is holding a perfectly legitimate publishable key.
| It is in the page source.
|
| Class A is 600 per key and 120 per IP, from the contract's table. The per-IP
| limit is the one a single client meets first, which is what these assert.
|
*/

function limitedKey(): string
{
    $tenant = Tenant::factory()->create();

    return Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Widget',
        type: ApiKeyType::Publishable,
        scopes: [ApiScope::BrandingRead],
    )->plainTextKey);
}

beforeEach(function (): void {
    Cache::flush();
});

/**
 * Spend the class-A per-IP allowance by making real requests.
 *
 * Driving `RateLimiter` directly would be faster and would prove nothing:
 * Laravel hashes the `by()` value inside `ThrottleRequests`, so a hand-built
 * key lands in a different bucket from the one the middleware uses, and the
 * test passes while the endpoint is unlimited.
 */
function spendPerIpAllowance(string $key, int $requests): void
{
    for ($i = 0; $i < $requests; $i++) {
        getJson('/api/v1/branding', ['Authorization' => "Bearer {$key}"]);
    }
}

it('SEC-6: reports the remaining quota on every response', function (): void {
    $key = limitedKey();

    $response = getJson('/api/v1/branding', ['Authorization' => "Bearer {$key}"])->assertOk();

    expect($response->headers->get('X-RateLimit-Limit'))->not->toBeNull()
        ->and($response->headers->get('X-RateLimit-Remaining'))->not->toBeNull();
})->group('fast');

it('SEC-6: throttles with 429 and a Retry-After once the per-IP limit is met', function (): void {
    // 120 per IP for class A, from the contract's table.
    $key = limitedKey();

    spendPerIpAllowance($key, 120);

    $response = getJson('/api/v1/branding', ['Authorization' => "Bearer {$key}"]);

    $response->assertStatus(429)
        // The standard envelope with the wait in the details, so a client can
        // back off without parsing prose (`docs/api.md` §3.6).
        ->assertJsonPath('error.code', 'rate_limited');

    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->json('error.details.retry_after_seconds'))->toBeInt();
})->group('fast', 'slow');

it('SEC-6: serves the last request inside the limit', function (): void {
    // A bound tested only from the far side is a bound that can be off by one,
    // and off by one here turns a busy operator's own homepage into a 429.
    $key = limitedKey();

    spendPerIpAllowance($key, 119);

    getJson('/api/v1/branding', ['Authorization' => "Bearer {$key}"])->assertOk();
})->group('fast', 'slow');

it('SEC-6: counts a 304 against the quota', function (): void {
    // `docs/api.md` §3.6: a 304 *"does consume rate-limit quota"*. Otherwise a
    // conditional request would be a free request, and the cheapest correct
    // thing a client can do would also be the way around the limit.
    $key = limitedKey();

    $etag = getJson('/api/v1/branding', ['Authorization' => "Bearer {$key}"])
        ->assertOk()->headers->get('ETag');

    $first = getJson('/api/v1/branding', [
        'Authorization' => "Bearer {$key}",
        'If-None-Match' => $etag,
    ]);

    $first->assertStatus(304);

    $second = getJson('/api/v1/branding', [
        'Authorization' => "Bearer {$key}",
        'If-None-Match' => $etag,
    ])->assertStatus(304);

    expect((int) $second->headers->get('X-RateLimit-Remaining'))
        ->toBeLessThan((int) $first->headers->get('X-RateLimit-Remaining'));
})->group('fast');

it('SEC-6: keys the per-key bucket on the id rather than the secret', function (): void {
    // The secret is hashed at rest and the id survives rotation, so rotating a
    // key must not hand an abuser a fresh bucket. Asserted on the limiter's own
    // definition, because reaching 600 requests in a test would cost more than
    // it proves.
    $limiter = RateLimiter::limiter('api-catalog');

    expect($limiter)->not->toBeNull();

    $key = limitedKey();
    $apiKey = ApiKey::query()->withoutGlobalScopes()->firstOrFail();

    $request = Request::create('/api/v1/branding');
    $request->attributes->set('api_key', $apiKey);

    $limits = $limiter($request);

    expect($limits[0]->key)->toBe('key:' . $apiKey->getKey())
        ->and($limits[0]->maxAttempts)->toBe(600)
        ->and($limits[1]->maxAttempts)->toBe(120);
})->group('fast');
