<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| The widget's first call has to be cheap — data-model §2.2, NFR-7
|--------------------------------------------------------------------------
|
| Every page load on every operator's site starts here, so the endpoint is
| cached and the cold path is a single indexed row read. The warm path adds
| nothing at all beyond authentication.
|
| A count is asserted rather than a duration: a benchmark is flaky and a count
| is exact.
|
*/

function brandingKeyFor(): string
{
    $tenant = Tenant::factory()->create();

    return Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Widget',
        type: ApiKeyType::Publishable,
        scopes: [ApiScope::BrandingRead],
    )->plainTextKey);
}

/**
 * Queries touching one table during a branding request.
 *
 * Counted per table rather than in total, deliberately. The total also contains
 * the authentication stack's own reads — and those are **doubled**, because
 * `AuthenticateApiKey` and `ResolveTenant`'s key resolver each look the key and
 * the tenant up independently. That duplication predates this endpoint and is
 * worth its own issue; folding it into this assertion would either hide it or
 * make this test fail the day somebody fixes it.
 *
 * What #35 owns is the line below: one `brand_profiles` read cold, none warm.
 *
 * @return array{0: int, 1: TestResponse<JsonResponse>}
 */
function brandingQueries(string $key, string $table = 'brand_profiles'): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = getJson('/api/v1/branding', ['Authorization' => "Bearer {$key}"]);

    $queries = array_filter(
        DB::getQueryLog(),
        static fn (array $query): bool => str_contains((string) $query['query'], $table),
    );

    DB::disableQueryLog();

    return [count($queries), $response];
}

it('reads the brand profile exactly once on a cold cache', function (): void {
    $key = brandingKeyFor();
    Cache::flush();

    [$count, $response] = brandingQueries($key);

    $response->assertOk();

    // §2.2: one indexed row read, on `brand_profiles.tenant_id`.
    expect($count)->toBe(1);
})->group('fast');

it('reads it not at all on a warm cache', function (): void {
    // Every page load on every operator's site starts here, so the second
    // visitor within the TTL must cost nothing but authentication.
    $key = brandingKeyFor();
    Cache::flush();

    brandingQueries($key);
    [$warm, $response] = brandingQueries($key);

    $response->assertOk();

    expect($warm)->toBe(0);
})->group('fast');

it('caches per tenant, so one operator cannot serve another brand', function (): void {
    // The failure a shared cache key would cause is the worst kind — correct
    // for the first caller and silently wrong for the second.
    $first = brandingKeyFor();
    $second = brandingKeyFor();

    Cache::flush();

    $a = getJson('/api/v1/branding', ['Authorization' => "Bearer {$first}"])->assertOk();
    $b = getJson('/api/v1/branding', ['Authorization' => "Bearer {$second}"])->assertOk();

    expect($a->json('data.tenant.uuid'))->not->toBe($b->json('data.tenant.uuid'));
})->group('fast');
