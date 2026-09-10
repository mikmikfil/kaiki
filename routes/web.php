<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\HostedHost;
use App\Http\Controllers\Guest\CheckoutController;
use App\Http\Controllers\Guest\GuestDetailsController;
use App\Http\Controllers\Guest\ManageBookingController;
use App\Http\Controllers\Guest\QuoteController;
use App\Http\Controllers\Guest\VoucherController;
use App\Http\Controllers\Hosted\ContactPageController;
use App\Http\Controllers\Hosted\HostedPageController;
use App\Http\Controllers\Hosted\ProductPageController;
use App\Http\Controllers\Hosted\RootController;
use App\Http\Controllers\Hosted\SearchPageController;
use App\Http\Controllers\IcalFeedController;
use App\Http\Controllers\SandboxCheckoutController;
use App\Http\Controllers\TlsAskController;
use App\Http\Controllers\Webhooks\GatewayWebhookController;
use App\Http\Controllers\WidgetBundleController;
use Illuminate\Support\Facades\Route;

/*
| `/` is **one** route that decides by host (#109).
|
| Laravel keys its route collection by method + domain + URI, so a second `/`
| with no domain constraint does not compete with this one — it *replaces* it.
| Registering a custom-domain root turned the platform's own front page into a
| 404, and the smoke test was the only thing that noticed.
|
| `RootController` asks whether the hostname resolved through
| `CustomDomainResolver` — which answers only for a verified row — and serves
| the operator's home page or the platform's, accordingly.
*/
Route::middleware(['hosted.root'])
    ->get('/', RootController::class)
    ->name('root');

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

    // `/c/{manage_token}` — pay for a draft. The same token as `/b/`, because a
    // draft is a booking and the person holding the link is the one who made it;
    // a separate path because paying for a draft and managing a confirmed
    // booking are different jobs, exactly as `/g/` and `/q/` are.
    Route::get('/c/{token}', [CheckoutController::class, 'show'])->name('guest.checkout');
    Route::post('/c/{token}', [CheckoutController::class, 'pay'])->name('guest.checkout.pay');

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
| The sandbox checkout page (spec SAA-9, PAY-11, and issue 111's Playwright run)
|--------------------------------------------------------------------------
|
| Where a **test** booking's payment actually happens. The fake gateway used to
| redirect to a host that could not resolve, which was fine while nothing was
| ever going to follow it — SAA-9's "a test booking in sandbox mode" and the
| end-to-end run both do.
|
| Deliberately outside every group above. It carries no tenant middleware (the
| payment names its own tenant), no `guest.token` (the reference is the
| credential, and it stops working the moment the payment leaves `pending`), and
| no throttle beyond the global one — brute-forcing a 24-character random
| reference to reach a page that refuses everything but a test booking buys
| nothing.
|
| The refusals live in the controller, all four of them, and the load-bearing
| one is the booking's own `is_test`.
*/
Route::get('/sandbox/checkout/{reference}', [SandboxCheckoutController::class, 'show'])
    ->name('sandbox.checkout');
Route::post('/sandbox/checkout/{reference}/pay', [SandboxCheckoutController::class, 'pay'])
    ->name('sandbox.checkout.pay');
Route::post('/sandbox/checkout/{reference}/fail', [SandboxCheckoutController::class, 'fail'])
    ->name('sandbox.checkout.fail');

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
/*
|--------------------------------------------------------------------------
| The widget bundle (WGT-4, ADR-0011 Option A)
|--------------------------------------------------------------------------
|
| A **versioned path** that is immutable for a year, and an **alias** that
| operators embed and that is repointed on release with a five-minute life. The
| split exists because an operator's `<script src>` lives in a WordPress theme
| nobody is going to edit: the version moves, the snippet does not.
|
| Served by a controller rather than as a static file because **the headers are
| the feature**, and a file served by the web server carries whatever that
| server was configured with — an M8 decision, in a different repository, whose
| failure mode is "the alias was cached for a year by a proxy".
|
| No tenant, no key: it is public JavaScript. The credential is the publishable
| key inside the requests the widget then makes.
*/
Route::get('/widget/manifest.json', [WidgetBundleController::class, 'manifest'])->name('widget.manifest');
Route::get('/widget/kaiki-widget.js', [WidgetBundleController::class, 'alias'])->name('widget.alias');
Route::get('/widget/{version}/kaiki-widget.js', [WidgetBundleController::class, 'versioned'])
    ->where('version', 'v[0-9A-Za-z.\-]{1,32}')
    ->name('widget.versioned');

/*
|--------------------------------------------------------------------------
| On-demand TLS: the ask endpoint (HOS-3, ADR-0010 Option A)
|--------------------------------------------------------------------------
|
| Caddy asks this before obtaining a certificate for a hostname it has never
| seen. **It is the only genuinely dangerous surface in the custom-domain
| feature**: an endpoint that answered broadly would let a stranger point DNS at
| the platform and burn through the certificate authority's rate limit for every
| operator at once.
|
| No `tenant` middleware and no API key — Caddy holds neither, and the question
| is asked *before* any tenant exists to resolve. The controller crosses tenants
| explicitly and answers on the row's own `verified` status.
|
| Not under `/api/v1`: it is not an operation an integrator calls, and putting it
| there would force it into a contract that does not describe it — the same
| reasoning the gateway webhooks are here rather than there.
*/
Route::get('/tls/ask', TlsAskController::class)
    ->middleware('throttle:webhooks')
    ->name('tls.ask');

/*
|--------------------------------------------------------------------------
| The vessel calendar feed (spec OPS-13, OPS-14)
|--------------------------------------------------------------------------
|
| Fetched by Google Calendar, Airbnb and whatever a marina office runs. None of
| them will send a header, hold a session or complete an OAuth flow, so **the
| token in the path is the entire authentication** — 40 hex characters from a
| CSPRNG, globally unique, revocable and rotatable in place.
|
| Outside every group above, for the same reason `/tls/ask` is: there is no host
| to resolve a tenant from and no key to authenticate with. The controller
| crosses tenants explicitly and re-enters the one the row names.
|
| `throttle:ical` rather than the global limiter — OPS-14 requires these feeds to
| be rate-limited, and the bucket is per IP because there is no key to count
| against. It is generous: a legitimate subscriber polls hourly, and the limit
| exists to make walking the token space expensive rather than to police normal
| use.
|
| The `.ics` suffix is part of the path rather than a query parameter, because
| several desktop clients decide how to treat a subscription URL by looking at
| its extension before they ever see a `Content-Type`.
*/
Route::get('/ical/{token}.ics', IcalFeedController::class)
    ->where('token', '[0-9a-f]{40}')
    ->middleware('throttle:ical')
    ->name('ical.feed');

// The host **name**, never the authority: `Route::domain()` matches against
// `$request->getHost()`, which does not include a port, so a constraint carrying
// one matches nothing and every hosted page 404s. See {@see HostedHost}.
Route::domain(HostedHost::name())
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

        // The contact page and its form. Registered before the trip route for
        // the same reason `/legal` and `/search` are, and the `POST` shares the
        // path so the form's `action` is the page's own address — which is what
        // a browser with scripts blocked submits to.
        //
        // The write is throttled on its own, at the same per-IP rate
        // `docs/api.md` §3.6 gives the enquiry endpoint (class F, five a
        // minute): it is the same Action writing the same table, and a form on
        // a public page is the more exposed of the two doors to it.
        Route::get('/{operator}/contact', [ContactPageController::class, 'show'])
            ->where('operator', '[a-z0-9][a-z0-9-]*')
            ->name('hosted.contact');

        Route::post('/{operator}/contact', [ContactPageController::class, 'send'])
            ->where('operator', '[a-z0-9][a-z0-9-]*')
            ->middleware('throttle:6,1')
            ->name('hosted.contact.send');

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

/*
|--------------------------------------------------------------------------
| The same hosted pages, at the root of an operator's own domain (HOS-3)
|--------------------------------------------------------------------------
|
| On `book.{platform-domain}` an operator's pages are `/{slug}`, `/{slug}/search`
| and `/{slug}/{product}`. On **their** domain there is no slug — they are the
| site — so the same pages answer at `/`, `/search` and `/{product}`.
|
| **Registered last, and guarded by `hosted.custom`.** A `/{product}` route at
| the root of every host is exactly what broke eight of #7's tests when #101
| tried it: it matches `/app`, `/admin`, `/up` and every probe route. The domain
| constraint that fixed it there is unavailable here, because the hostname is
| the operator's and unknown in advance — so the guard is a middleware that
| refuses any host which did not resolve through `CustomDomainResolver`, and
| that resolver only answers for a **verified** row.
|
| The controllers are the same ones. `{operator}` is filled from the resolved
| tenant rather than from the path, which is the only difference between the two
| shapes of URL.
*/
Route::middleware(['tenant', 'hosted.custom', 'hosted.page', 'locale'])->group(function (): void {
    // No `/` here: it is registered above, once, because a second one would
    // replace it rather than compete with it. See `RootController`.
    Route::get('/legal', [HostedPageController::class, 'legal'])->name('hosted.custom.legal');
    Route::get('/search', [SearchPageController::class, 'show'])->name('hosted.custom.search');
    Route::get('/contact', [ContactPageController::class, 'show'])->name('hosted.custom.contact');

    Route::post('/contact', [ContactPageController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('hosted.custom.contact.send');

    Route::get('/{product}', [ProductPageController::class, 'show'])
        ->where('product', '[a-z0-9][a-z0-9-]*')
        ->name('hosted.custom.product');
});
