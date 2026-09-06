<?php

declare(strict_types=1);

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
