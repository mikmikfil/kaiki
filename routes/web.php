<?php

declare(strict_types=1);

use App\Http\Controllers\Guest\GuestDetailsController;
use App\Http\Controllers\Guest\ManageBookingController;
use App\Http\Controllers\Guest\QuoteController;
use App\Http\Controllers\Guest\VoucherController;
use App\Http\Controllers\Hosted\HostedPageController;
use App\Http\Controllers\Hosted\ProductPageController;
use App\Http\Controllers\Hosted\SearchPageController;
use App\Http\Controllers\Webhooks\GatewayWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Gateway webhooks (spec PAY-5, PAY-6, PAY-7)
|--------------------------------------------------------------------------
|
| **Here rather than under `/api/v1`, deliberately.** That prefix is the public
| API, every route under it is compared against `docs/api.md` §5 by the drift
| gate, and a webhook is not an operation an integrator calls — it is a callback
| from a payment provider. Putting it there would either break the gate or force
| an endpoint into a contract that does not describe it.
|
| **No `api.key`, no `tenant`, no CSRF.** A gateway holds none of the three. The
| tenant is resolved from the payload instead, which is what
| `payments_gateway_ref_idx` and `integration_credentials.external_account_id`
| exist for — both deliberately not tenant-first. CSRF is excluded in
| `bootstrap/app.php`, by path.
|
| Throttled per IP (PAY-7). The public API's limiters key on the API key, which
| a gateway does not present, so this has its own — generous enough for a busy
| Saturday's retries and low enough that a forged stream is capped.
*/
Route::post('/webhooks/{provider}', GatewayWebhookController::class)
    ->middleware('throttle:webhooks')
    ->name('webhooks.gateway');

/*
|--------------------------------------------------------------------------
| The four tokenised guest pages (spec TOK-1 … TOK-13)
|--------------------------------------------------------------------------
|
| TOK-1 is FIXED: `/b/{manage_token}`, `/g/{guest_details_token}`,
| `/q/{quote_token}` and `/v/{voucher}`. These are the only surfaces where a
| **guest** acts on their own booking, and the only place in the product where
| **a URL is a credential**. Everything in TOK-2 to TOK-5 follows from that one
| sentence.
|
| **No `api.key` and no `tenant` middleware**, and that is not an omission. A
| guest has neither; the token is the whole of the authentication, and the
| lookup is what resolves the tenant — which is why every column behind these
| routes is globally unique with no tenant prefix.
|
| **CSRF stays on.** TOK-13: every state-changing action is a POST with CSRF
| protection. The webhook route above is excluded by path in `bootstrap/app.php`
| because a gateway holds no session; a guest's browser does, and these forms
| are rendered by this application.
|
| `guest.token` sets TOK-3's three headers on every response including the
| failure page; `guest.throttle` applies TOK-4's two limits. Both are middleware
| rather than controller code, because a header set in a controller is a header
| the fifth page forgets.
*/
Route::middleware(['guest.token', 'guest.throttle'])->group(function (): void {
    Route::get('/b/{token}', [ManageBookingController::class, 'show'])->name('guest.booking');
    // TOK-6's downloadable e-ticket (#88's PDF, added by #89). A GET, and the
    // only route to a file on the private disk.
    Route::get('/b/{token}/ticket', [ManageBookingController::class, 'ticket'])->name('guest.ticket');
    Route::post('/b/{token}/cancel', [ManageBookingController::class, 'cancel'])->name('guest.booking.cancel');
    Route::post('/b/{token}/pay-balance', [ManageBookingController::class, 'payBalance'])->name('guest.booking.pay-balance');
    Route::post('/b/{token}/weather-choice', [ManageBookingController::class, 'weatherChoice'])->name('guest.booking.weather-choice');
    Route::post('/b/{token}/contact', [ManageBookingController::class, 'updateContact'])->name('guest.booking.contact');

    Route::get('/g/{token}', [GuestDetailsController::class, 'show'])->name('guest.details');
    Route::post('/g/{token}', [GuestDetailsController::class, 'save'])->name('guest.details.save');

    Route::get('/q/{token}', [QuoteController::class, 'show'])->name('guest.quote');
    Route::post('/q/{token}/accept', [QuoteController::class, 'accept'])->name('guest.quote.accept');
    Route::post('/q/{token}/decline', [QuoteController::class, 'decline'])->name('guest.quote.decline');
    Route::post('/q/{token}/request-new', [QuoteController::class, 'requestNew'])->name('guest.quote.request-new');

    Route::get('/v/{code}', [VoucherController::class, 'show'])->name('guest.voucher');
});

/*
|--------------------------------------------------------------------------
| Hosted operator pages (spec HOS-1 … HOS-10)
|--------------------------------------------------------------------------
|
| `book.{platform-domain}/{operator-slug}` — the page an operator with no
| website of their own hands out, and the first guest-facing surface that is
| not a token link.
|
| **Scoped to the hosted host**, which is the guard rather than the registration
| order. `{operator}` is a single path segment; on every host it would swallow
| `/app`, `/admin` and any probe route a test registers — it did, and it broke
| eight of #7's own tenant-resolution tests the moment it landed. `TEN-4`'s
| third strategy already reads the first segment as a slug on that host and
| nowhere else, and `HostedSlugResolver` already declines an operator whose page
| is switched off, so `tenant` does the resolving and HOS-6's 404 comes for
| free.
|
| No `guest.throttle` here, unlike the token pages: a hosted page is public and
| indexable by design (HOS-2), and rate-limiting a crawler is how an operator
| disappears from search. The pages are cacheable reads with no credential in
| the URL, which is exactly what the token pages are not.
*/
Route::domain((string) config('kaiki.tenancy.hosted_host'))
    ->middleware(['tenant', 'hosted.page', 'locale'])
    ->group(function (): void {
        // Constrained to the shape a `tenants.slug` actually has. Without it
        // `{operator}` matches *any* first segment on this host, including the
        // `/_probe` route #7's own tenant tests declare — and a catch-all that
        // swallows a route somebody else registered is a catch-all that will
        // swallow the next one too.
        Route::get('/{operator}', [HostedPageController::class, 'index'])
            ->where('operator', '[a-z0-9][a-z0-9-]*')
            ->name('hosted.index');

        Route::get('/{operator}/legal', [HostedPageController::class, 'legal'])
            ->where('operator', '[a-z0-9][a-z0-9-]*')
            ->name('hosted.legal');

        // The catalogue search (#105), registered before the trip route for the
        // same reason `/legal` is: two segments, first match wins, and a
        // shadowed search page would be the feature unreachable for everybody
        // rather than one operator renaming a slug.
        Route::get('/{operator}/search', [SearchPageController::class, 'show'])
            ->where('operator', '[a-z0-9][a-z0-9-]*')
            ->name('hosted.search');

        // One trip (#104). **Registered after `/legal` and `/search`**, which is not a style
        // choice: both match two segments, Laravel takes the first that does,
        // and the reverse order would make the legal page unreachable for every
        // operator. The cost is that a product whose slug is literally `legal`
        // is shadowed — `products_tenant_slug_unique` cannot express that, so it
        // is written down here and asserted in `ProductPageTest`.
        Route::get('/{operator}/{product}', [ProductPageController::class, 'show'])
            ->where('operator', '[a-z0-9][a-z0-9-]*')
            // The shape a `products.slug` actually has, so a request for an
            // asset path or an uppercase URL never reaches a detail query.
            ->where('product', '[a-z0-9][a-z0-9-]*')
            ->name('hosted.product');
    });
