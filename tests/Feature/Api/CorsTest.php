<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| CORS comes from the key, not from the application — SEC-7, api.md §3.7
|--------------------------------------------------------------------------
|
| Laravel's own CORS config is one list for the whole app, which is exactly
| wrong here: a publishable key belongs on its operator's site and nowhere else,
| and one shared allow-list would let any tenant's key be used from any tenant's
| page.
|
| Two layers, and the order matters. `AuthenticateApiKey` (#6) **refuses** an
| unlisted origin with a 403 before anything else runs — server-side, so a
| non-browser client cannot skip it by not being a browser. `ApiKeyCors` then
| tells the browser about the origins that *were* allowed, which is what lets a
| legitimate widget read the response it was permitted to make.
|
| Neither alone is enough: without the 403 the allow-list would be advisory,
| and without the headers a permitted widget could not read its own response.
|
*/

/** @param list<string> $origins */
function corsKey(array $origins): string
{
    $tenant = Tenant::factory()->create();

    return Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Widget',
        type: ApiKeyType::Publishable,
        scopes: [ApiScope::BrandingRead],
        allowedOrigins: $origins,
    )->plainTextKey);
}

/** @return TestResponse<JsonResponse> */
function getWithOrigin(string $key, ?string $origin): TestResponse
{
    $headers = ['Authorization' => "Bearer {$key}"];

    if ($origin !== null) {
        $headers['Origin'] = $origin;
    }

    return getJson('/api/v1/branding', $headers);
}

it('SEC-7: permits a listed origin, echoing it exactly', function (): void {
    // The exact origin rather than `*`: a wildcard cannot carry credentials and
    // tells every other site it is welcome too.
    $key = corsKey(['https://aegeancruises.gr']);

    $response = getWithOrigin($key, 'https://aegeancruises.gr')->assertOk();

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://aegeancruises.gr')
        // Without `Vary`, a shared cache would serve one site's headers to
        // another — which is the one CORS bug that survives a code review.
        ->and($response->headers->get('Vary'))->toContain('Origin');
})->group('fast');

it('SEC-7: refuses an unlisted origin outright, and sends no allow header', function (): void {
    // Server-side, before the controller. Leaving this to the browser would
    // make the allow-list advisory — a scripted client simply would not honour
    // it.
    $key = corsKey(['https://aegeancruises.gr']);

    $response = getWithOrigin($key, 'https://someoneelse.gr')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'origin_not_allowed');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
})->group('fast');

it('SEC-7: an empty allow-list permits any origin', function (): void {
    // The documented default for a key nobody has narrowed yet, which the panel
    // warns about (#10).
    $key = corsKey([]);

    expect(getWithOrigin($key, 'https://anywhere.example')->headers->get('Access-Control-Allow-Origin'))
        ->toBe('https://anywhere.example');
})->group('fast');

it('SEC-7: a narrowed publishable key is refused without an Origin at all', function (): void {
    // A publishable key with an allow-list is a browser key by construction —
    // it lives in a page. A request with no `Origin` is therefore not the
    // browser it was issued for, and #6 refuses it rather than treating a
    // missing header as permission.
    $key = corsKey(['https://aegeancruises.gr']);

    getWithOrigin($key, null)->assertForbidden();
})->group('fast');

it('SEC-7: an un-narrowed key still works server to server', function (): void {
    // The WordPress plugin, a cron, our own tests. None of them send an
    // `Origin`, and CORS has nothing to say about any of them.
    $key = corsKey([]);

    $response = getWithOrigin($key, null)->assertOk();

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
})->group('fast');

it('SEC-7: matches an origin case-insensitively and ignoring a trailing slash', function (): void {
    // Both arrive from real integrations, and neither is a different site.
    $key = corsKey(['https://AegeanCruises.gr/']);

    expect(getWithOrigin($key, 'https://aegeancruises.gr')->headers->get('Access-Control-Allow-Origin'))
        ->toBe('https://aegeancruises.gr');
})->group('fast');

it('SEC-7: answers a preflight before the route runs', function (): void {
    // A preflight never reaches a controller, so answering it early is the only
    // way it can succeed for a key-scoped endpoint.
    $key = corsKey(['https://aegeancruises.gr']);

    $response = call('OPTIONS', '/api/v1/branding', [], [], [], [
        'HTTP_ORIGIN' => 'https://aegeancruises.gr',
        'HTTP_AUTHORIZATION' => "Bearer {$key}",
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://aegeancruises.gr')
        ->and($response->headers->get('Access-Control-Allow-Headers'))->toContain('If-None-Match');
})->group('fast');

it('allows every header the widget actually sends, Idempotency-Key included', function (): void {
    // **The bug issue 111 found, and the reason it could only be found here.**
    //
    // `Idempotency-Key` is required on every POST that creates a booking
    // (§3.4). It is a custom header, so it makes the request non-simple, so the
    // browser preflights — and a preflight that does not name it fails. No
    // widget on any operator's site could create a booking, and every
    // server-side test passed, because a test client does not preflight.
    //
    // Asserted header by header rather than as one string, so a future edit
    // that drops one fails on the name of the one it dropped.
    $key = corsKey(['https://aegeancruises.gr']);

    $response = call('OPTIONS', '/api/v1/bookings', [], [], [], [
        'HTTP_ORIGIN' => 'https://aegeancruises.gr',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,idempotency-key,authorization',
    ]);

    $allowed = array_map(
        static fn (string $header): string => strtolower(trim($header)),
        explode(',', (string) $response->headers->get('Access-Control-Allow-Headers')),
    );

    foreach (['authorization', 'content-type', 'accept', 'accept-language', 'if-none-match', 'idempotency-key', 'x-kaiki-key', 'x-kaiki-guest-token'] as $header) {
        expect($allowed)->toContain($header);
    }
})->group('fast');

it('exposes the headers a widget actually needs to read', function (): void {
    // A cross-origin caller can read no response header it was not told about,
    // so an unexposed `ETag` makes conditional polling impossible from a browser
    // — quietly, because the header is right there in the devtools.
    $key = corsKey([]);

    $exposed = (string) getWithOrigin($key, 'https://anywhere.example')
        ->headers->get('Access-Control-Expose-Headers');

    expect($exposed)->toContain('ETag')
        ->and($exposed)->toContain('X-RateLimit-Remaining')
        ->and($exposed)->toContain('Retry-After');
})->group('fast');
