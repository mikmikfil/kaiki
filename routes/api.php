<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PriceQuoteController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SyncProductController;
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

/*
 * Class B — availability (`docs/api.md` §3.6), its own bucket at 1200/240.
 *
 * The hot path: a calendar mount fires one of these per month view, so it is
 * sized twice the catalogue class precisely so a busy operator's own homepage
 * cannot throttle its own visitors.
 *
 * `availability.read` rather than `products.read`. They are separate scopes in
 * `ApiScope` because a key issued for an SEO sync has no business asking who
 * has seats left on Tuesday, and a scope that covers both would make that
 * distinction unexpressible.
 */
Route::middleware([
    'api.key',
    'tenant',
    'locale',
    'api.scope:availability.read',
    'throttle:api-availability',
])->group(function (): void {
    Route::get('/availability', AvailabilityController::class)->name('api.v1.availability');

    /*
     * The catalogue search (#105) shares this group deliberately.
     *
     * `availability.read` rather than `products.read`, because that is what it
     * discloses: which boats have seats on Saturday. A key issued for an SEO
     * sync has no business asking that, and putting search in the catalogue
     * scope would make the distinction `ApiScope` draws unenforceable.
     *
     * Class B for the same reason — the payload carries seat counts, so it
     * caches for thirty seconds like availability rather than sixty like the
     * catalogue.
     */
    Route::get('/search', SearchController::class)->name('api.v1.search');
});

/*
 * Class C — pricing (`docs/api.md` §3.6).
 *
 * **A POST, and deliberately not a booking write.** It reserves nothing and
 * changes nothing, so it belongs in the pricing bucket at 600/120 rather than
 * the write bucket at 60/20 — a guest adjusting the party size would otherwise
 * be throttled while browsing.
 *
 * For the same reason `tenant.writable` is **absent**: SAA-7 closes bookings
 * for a lapsed subscription, not prices. The refusal a guest needs to see comes
 * from the availability engine's `tenant_read_only`, at the point of booking.
 *
 * The scope is **`availability.read`**, which §2.1's table assigns and which
 * reads oddly on a POST until the reason lands: a scope licenses what a key may
 * *ask about*, and this asks what a trip costs. `quotes.write` is for the
 * operator-built quote flow in M2, where something is actually written — giving
 * it to this endpoint would mean a widget's publishable key had to hold a write
 * scope to show a price.
 */
Route::middleware([
    'api.key',
    'tenant',
    'locale',
    'api.scope:availability.read',
    'throttle:api-pricing',
])->group(function (): void {
    Route::post('/price-quote', PriceQuoteController::class)->name('api.v1.price-quote');
});

/*
 * Class F — enquiries (`docs/api.md` §3.6), and the tightest per-IP limit here.
 *
 * 120 per key, **5 per IP**. Four times tighter than anything else in the
 * contract, because this is the one endpoint whose whole purpose is to accept
 * unstructured text from a stranger — and the honeypot and timing check in
 * `EnquiryCreateRequest` are filters on top of that number, not instead of it.
 *
 * The scope is `bookings.write`, which §2.1's table assigns and which reads
 * oddly on a form that creates no booking. It is right anyway: an enquiry is
 * the call to action on a `mode: quote` product where no price is shown, so a
 * publishable key that may start a booking is exactly the key that may ask a
 * question. Giving it `products.read` would let an SEO-sync key post messages.
 *
 * **`tenant.writable` is absent**, deliberately and unlike every other write.
 * SAA-7 closes *bookings* for a lapsed subscription. Refusing a guest's
 * question would cost the operator the enquiry that pays the bill they are
 * behind on.
 */
Route::middleware([
    'api.key',
    'tenant',
    'locale',
    'api.scope:bookings.write',
    'throttle:api-enquiries',
])->group(function (): void {
    Route::post('/enquiries', EnquiryController::class)->name('api.v1.enquiries.store');
});

/*
 * Class D — booking writes, and class E for the one read (`docs/api.md` §3.6).
 *
 * 60 per key and 20 per IP on the writes: the tightest limit outside enquiries,
 * because each of these takes a seat, a gateway session or a refund. The read
 * sits at 120/60, because a guest refreshing `/b/{token}` on the morning of
 * their trip is not an attack and must not be throttled into thinking their
 * booking has vanished.
 *
 * **`tenant.writable` is present here and absent from the catalogue reads**, and
 * that asymmetry is SAA-7: a lapsed subscription stops the operator taking new
 * money and never touches a guest who already holds a booking. #36 and #37 left
 * the reads open for exactly that reason; these are the writes it closes.
 *
 * `idempotency:required` on all three writes (§3.4), and the middleware is one
 * class rather than three guards — see `EnforceIdempotencyKey`.
 *
 * **`api.guest` is where a publishable key is refused for the first time in the
 * product.** §2.1's table gives `GET /bookings/{uuid}` and `POST /cancel` to a
 * `manage_token` or an `sk_` and to nothing else: the caller is claiming to be
 * a specific guest rather than a specific website, and a `pk_` sits in the
 * source of somebody's home page. Checkout takes `api.guest:optional`, which is
 * footnote 1 — a `pk_` may finish the flow it started while the booking is
 * still a live draft, and not one moment later.
 */
Route::middleware([
    'api.key',
    'tenant',
    'tenant.writable',
    'locale',
    'api.scope:bookings.write',
    'throttle:api-booking-writes',
    'idempotency:required',
])->group(function (): void {
    Route::post('/bookings', [BookingController::class, 'store'])->name('api.v1.bookings.store');
});

Route::middleware([
    'api.key',
    'tenant',
    'locale',
    'api.scope:bookings.write',
    'throttle:api-guest',
    'api.guest:required',
])->group(function (): void {
    Route::get('/bookings/{uuid}', [BookingController::class, 'show'])->name('api.v1.bookings.show');
});

Route::middleware([
    'api.key',
    'tenant',
    'tenant.writable',
    'locale',
    'api.scope:bookings.write',
    'throttle:api-booking-writes',
    'idempotency:required',
    'api.guest:optional',
])->group(function (): void {
    Route::post('/bookings/{uuid}/checkout', [BookingController::class, 'checkout'])->name('api.v1.bookings.checkout');
});

Route::middleware([
    'api.key',
    'tenant',
    'tenant.writable',
    'locale',
    'api.scope:bookings.write',
    'throttle:api-booking-writes',
    'idempotency:required',
    'api.guest:required',
])->group(function (): void {
    Route::post('/bookings/{uuid}/cancel', [BookingController::class, 'cancel'])->name('api.v1.bookings.cancel');
});

/*
 * Class H — the catalogue sync (`docs/api.md` §3.6), and the only route in this
 * file that a publishable key cannot reach.
 *
 * `api.secret` is the difference. The payload is the whole catalogue including
 * `draft`, `inactive` and `archived` products and the operator's internal SEO
 * fields — the one read an unfriendly party would want — and a publishable key
 * sits in the source of somebody's home page (ADR-0013 Option A, §2.2). It is
 * not expressible as a scope: `products.read` is in a publishable key's default
 * read set, so granting this feed by scope would be the mistake rather than the
 * fix.
 *
 * `tenant.writable` is absent for the reason the catalogue reads give: SAA-7
 * closes bookings for a lapsed subscription, not the catalogue. An operator's
 * WordPress site should not start unpublishing their trips because a card
 * expired.
 *
 * 120 per key and 120 per IP — the only class whose two numbers are equal,
 * because there is no browser here to tell apart from the server behind it.
 */
Route::middleware([
    'api.key',
    'api.secret',
    'tenant',
    'locale',
    'api.scope:products.read',
    'throttle:api-sync',
])->group(function (): void {
    Route::get('/sync/products', SyncProductController::class)->name('api.v1.sync.products');
});
