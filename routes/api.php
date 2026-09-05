<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API v1 (spec ARC-5, docs/api.md)
|--------------------------------------------------------------------------
|
| `docs/api.md` is the contract and this file is the implementation's view of
| it. The direction of authority runs contract → code (§10.5): when the two
| disagree the fix is to change the code, or to change the contract *and say
| why in CHANGELOG.md*. `docs/api.md` is never regenerated from here.
|
| Every route is authenticated by an API key and scoped to exactly one tenant.
| There is no unauthenticated route in this file and there must never be one —
| an endpoint with no key has no tenant, and an endpoint with no tenant is a
| cross-tenant read waiting to happen (ADR-0001).
|
| Middleware, in order:
|   api.key   turns a presented key into a resolved tenant (#6)
|   tenant    fixes exactly one tenant for the request, or 404s (#7)
|   locale    sets the response language by the I18N-5 chain (#12) — ordered
|             after `tenant` by the middleware priority list, not by this line
|
| Rate limiting (SEC-6) arrived with #35 and is applied **per class**, from the
| table in `docs/api.md` §3.6 — never per route, because two endpoints in one
| class must share one number and a per-route limit is how they stop doing so.
|
| CORS is **not** in this list. `ApiKeyCors` is global, because a browser sends
| no `Authorization` on a preflight and an `OPTIONS` request with no matching
| route is a 405 before any group middleware runs. The allow-list itself is
| enforced server-side by `api.key` (#6), which refuses an unlisted origin with
| a 403 — the headers are only how the browser is told.
|
*/

Route::middleware(['api.key', 'tenant', 'locale'])->group(function (): void {
    Route::get('/health', HealthController::class)->name('api.v1.health');
});

/*
 * Class A — catalogue reads (`docs/api.md` §3.6).
 *
 * `api.scope` refuses a key without `branding.read` before the controller
 * loads, so a narrowed key is turned away by the middleware rather than by an
 * endpoint remembering to check.
 */
Route::middleware([
    'api.key',
    'tenant',
    'locale',
    'api.scope:branding.read',
    'throttle:api-catalog',
])->group(function (): void {
    Route::get('/branding', BrandingController::class)->name('api.v1.branding');
});

/*
 * Class A, continued — the catalogue reads themselves (#36).
 *
 * A separate group because the scope differs: `branding.read` above,
 * `products.read` here. The rate limiter is the same `api-catalog` bucket on
 * purpose — §3.6 sets one number for the whole class, and a per-route limit is
 * how two endpoints in one class quietly stop sharing it.
 *
 * **`tenant.writable` is deliberately absent.** SAA-7: a lapsed subscription
 * closes bookings, not the catalogue. An operator whose card expired should
 * still have their trips visible on their own website while they sort it out.
 *
 * The `{uuid}` segment also accepts a `slug` (`ProductIdentifierPath`), which is
 * what the hosted page and the WordPress permalink resolve with. It is named
 * `uuid` because that is the name in the contract, and the CNV-8 gate in
 * `OpenApiDriftTest` reads route parameter names to prove no endpoint resolves
 * by database id.
 */
Route::middleware([
    'api.key',
    'tenant',
    'locale',
    'api.scope:products.read',
    'throttle:api-catalog',
])->group(function (): void {
    Route::get('/products', [ProductController::class, 'index'])->name('api.v1.products.index');
    Route::get('/products/{uuid}', [ProductController::class, 'show'])->name('api.v1.products.show');
});
