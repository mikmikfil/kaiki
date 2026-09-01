<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureTenantIsWritable;
use App\Http\Middleware\RequireApiKeyCapability;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

/*
 * The order of the API middleware chain, pinned.
 *
 * This is not a style assertion. Laravel's `SortedMiddleware` reorders only the
 * middleware present in the priority map, moving them around the ones that are
 * not — so adding one entry silently changes the order of every route that
 * mixes mapped and unmapped middleware, whatever the route itself lists.
 *
 * That is not hypothetical. #12 added `ResolveTenant` to the map to fix a
 * locale bug, and #11's first health test found the consequence: a request to
 * `/api/v1/health` with **no key at all** returned `404` instead of `401`,
 * because tenant resolution had been hoisted above the middleware whose entire
 * job is to say "no key". Nothing in `routes/api.php` changed; the route still
 * listed `api.key` first.
 *
 * A 404 where a 401 belongs is not cosmetic. It tells an integrator "this
 * endpoint does not exist" when the truth is "your key is missing", and it is
 * the difference between a five-minute fix and an afternoon.
 */

/** @return list<string> */
function chainFor(string $uri): array
{
    $route = null;

    foreach (Route::getRoutes()->getRoutes() as $candidate) {
        if ($candidate->uri() === $uri) {
            $route = $candidate;

            break;
        }
    }

    expect($route)->not->toBeNull("no route registered for {$uri}");
    assert($route instanceof RoutingRoute);

    // Parameters are stripped: `api.scope:quotes.write` resolves to
    // `RequireApiKeyCapability:quotes.write`, so an exact class comparison
    // silently finds nothing and the test passes by matching zero middleware.
    return array_values(array_map(
        static fn (string $entry): string => strtok($entry, ':') ?: $entry,
        array_filter(app('router')->gatherRouteMiddleware($route), is_string(...)),
    ));
}

it('authenticates the key before resolving the tenant', function (): void {
    $chain = chainFor('api/v1/health');

    $key = array_search(AuthenticateApiKey::class, $chain, strict: true);
    $tenant = array_search(ResolveTenant::class, $chain, strict: true);

    expect($key)->not->toBeFalse()
        ->and($tenant)->not->toBeFalse()
        ->and($key)->toBeLessThan($tenant, 'a missing key must be 401, not a 404 from tenant resolution');
})->group('fast', 'api-docs');

it('sets the locale after the tenant, so the tenant default is reachable', function (): void {
    $chain = chainFor('api/v1/health');

    $tenant = array_search(ResolveTenant::class, $chain, strict: true);
    $locale = array_search(SetLocale::class, $chain, strict: true);

    expect($locale)->not->toBeFalse()
        ->and($locale)->toBeGreaterThan($tenant, 'step 5 of I18N-5 needs the tenant to exist');
})->group('fast', 'api-docs');

it('keeps the whole chain in the documented order on a route that lists it backwards', function (): void {
    // The real guarantee: the order is a property of the application, not of
    // how carefully whoever wrote the route typed the array.
    Route::middleware(['tenant.writable', 'locale', 'tenant', 'api.scope:quotes.write', 'api.key'])
        ->post('/api/v1/_test/backwards', fn () => response()->json(['ok' => true]));

    $chain = chainFor('api/v1/_test/backwards');

    $positions = [];

    foreach ([
        AuthenticateApiKey::class,
        RequireApiKeyCapability::class,
        ResolveTenant::class,
        SetLocale::class,
        EnsureTenantIsWritable::class,
    ] as $middleware) {
        $at = array_search($middleware, $chain, strict: true);

        expect($at)->not->toBeFalse("{$middleware} is missing from the chain");

        $positions[] = $at;
    }

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted, 'chain out of order: ' . implode(' → ', $chain));
})->group('fast', 'api-docs');

it('answers a keyless request with 401 rather than 404', function (): void {
    // The end-to-end version of the first assertion, because the ordering is
    // only interesting for what it makes the API say.
    getJson('/api/v1/health')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'missing_key');
})->group('fast', 'api-docs');
