<?php

declare(strict_types=1);

use App\Http\Controllers\Guest\GuestDetailsController;
use App\Http\Controllers\Guest\ManageBookingController;
use App\Http\Controllers\Guest\QuoteController;
use App\Http\Controllers\Guest\VoucherController;
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
