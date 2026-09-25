<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Contracts\ProvidesTransactionStatus;
use App\Contracts\ProvidesWebhookVerificationKey;
use App\Domain\Integrations\Support\CredentialRepository;
use App\Domain\Payments\Data\GatewayTransaction;
use App\Domain\Payments\Data\RedirectTarget;
use App\Domain\Payments\Data\RefundResult;
use App\Domain\Payments\Data\TranslatableMessage;
use App\Domain\Payments\Support\GatewayErrorDictionary;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Models\Booking;
use App\Models\IntegrationCredential;
use App\Models\Payment;
use App\Support\Tenancy;
use Brick\Money\Money;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Viva Wallet Smart Checkout, on the **operator's own account** (PAY-1, PAY-2).
 *
 * ## Everything Viva does differently is absorbed here
 *
 * ADR-0004 fixes the contract at four methods and the issue says it outright:
 * *"resist widening the contract to accommodate that — it belongs inside
 * `VivaSmartCheckoutGateway`."* Three differences, all handled below:
 *
 * 1. **It returns an order code, not a URL.** Stripe hands back a hosted link;
 *    Viva hands back a number, and the redirect is built from it against a
 *    *different host* from the API. Both hosts are config values.
 *
 * 2. **It is OAuth2, not a bearer key.** Every call needs a token minted from
 *    client credentials, so there is a round trip Stripe does not have. The
 *    token is cached for slightly less than its own lifetime, because minting
 *    one per checkout doubles the latency of the slowest step in a booking.
 *
 * 3. **It does not sign its webhooks.** Viva authenticates the *receiver*
 *    instead: our endpoint must answer a verification challenge with a key
 *    derived from the same credentials. {@see self::verifyWebhook()} therefore
 *    checks a shared secret rather than an HMAC — different mechanism, same
 *    guarantee, and the difference stops at this class.
 *
 * ## Amounts are minor units, and Viva means it
 *
 * `amount` is in cents. Sending euros produces a charge a hundred times too
 * small and a support call from a delighted guest, which is why every money
 * value in this project is an integer of cents end to end (`CLAUDE.md`).
 *
 * ## No endpoint is written into this class
 *
 * `config('kaiki.payments.viva')`, per `CLAUDE.md`, and the sandbox and live
 * hosts genuinely differ — Viva uses separate domains rather than separate
 * keys, so an environment mix-up is a request to the wrong *server* rather than
 * a rejected credential.
 */
final class VivaSmartCheckoutGateway implements PaymentGateway, ProvidesTransactionStatus, ProvidesWebhookVerificationKey
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CredentialRepository $credentials,
    ) {}

    public function createCheckoutSession(Booking $booking, PaymentKind $kind, Money $amount): RedirectTarget
    {
        $environment = $booking->is_test ? CredentialEnvironment::Test : CredentialEnvironment::Live;
        $credential = $this->credentialFor($environment);

        $response = $this->call(
            fn (): Response => $this->http
                ->withToken($this->accessToken($credential, $environment))
                ->timeout((int) config('kaiki.payments.timeout_seconds'))
                ->post($this->apiHost($environment) . '/checkout/v2/orders', [
                    // **Minor units.** See the class docblock.
                    'amount' => (int) $amount->getMinorAmount()->toInt(),
                    'customerTrns' => (string) trans(
                        'payments.checkout.line_item',
                        ['reference' => $booking->reference],
                        $booking->locale,
                    ),
                    'customer' => [
                        'email' => $booking->guest_email,
                        'fullName' => $booking->guest_name,
                        // Viva renders its own page in this language, so it is
                        // the *booking's* locale rather than the operator's.
                        'countryCode' => $booking->guest_country ?? strtoupper((string) config('kaiki.defaults.country')),
                        'requestLang' => $booking->locale,
                    ],
                    'paymentTimeout' => (int) config('kaiki.booking.checkout_expiry_minutes') * 60,
                    'merchantTrns' => $booking->reference,
                    // The source code identifies which of the merchant's payment
                    // sources takes this — the non-secret half of the credential
                    // (#79's `public_config`).
                    'sourceCode' => (string) ($credential->public_config['source_code'] ?? ''),
                ]),
        );

        $body = $response->json();
        $orderCode = is_array($body) ? ($body['orderCode'] ?? null) : null;

        if (! is_int($orderCode) && ! is_string($orderCode)) {
            throw GatewayCallFailed::forCode(PaymentGatewayName::Viva, '404');
        }

        $orderCode = (string) $orderCode;

        return new RedirectTarget(
            // Built here, because Viva gives us a number and not a link. A
            // *different host* from the API, which is the sort of thing that
            // looks like a typo until it is written down.
            url: $this->checkoutHost($environment) . '/web/checkout?ref=' . $orderCode,
            reference: $orderCode,
            context: ['source_code' => $credential->public_config['source_code'] ?? null],
        );
    }

    /**
     * Viva does not sign its webhooks, so a shared secret stands in.
     *
     * Stripe's HMAC-over-body is the stronger mechanism and is not available
     * here. What Viva offers is a verification key the receiver proves it holds
     * — so the check is that the request carries the secret we stored, compared
     * in constant time.
     *
     * The tenant is resolved from the source code in the payload, which is
     * exactly what `integration_credentials.external_account_id` and its
     * deliberately not-tenant-first index exist for (#79): a webhook arrives
     * before tenancy does.
     */
    public function verifyWebhook(Request $request, ?IntegrationCredential $credential = null): bool
    {
        $presented = (string) $request->header('X-Viva-Verification', '');

        if ($presented === '') {
            return false;
        }

        if (! $credential instanceof IntegrationCredential) {
            // No operator in the URL: the old shared address, which has to read
            // the payload to find out whose webhook this is.
            $sourceCode = (string) data_get($request->all(), 'EventData.SourceCode', '');

            $credential = $sourceCode === ''
                ? $this->credentials->find(IntegrationProvider::Viva, CredentialEnvironment::Live)
                : IntegrationCredential::findByExternalAccount(IntegrationProvider::Viva, $sourceCode);
        }

        $secret = $credential?->webhook_secret;

        if (! is_string($secret) || $secret === '') {
            // Unverifiable is refused, not trusted (PAY-5, PAY-7).
            return false;
        }

        return hash_equals($secret, $presented);
    }

    public function refund(Payment $payment, Money $amount): RefundResult
    {
        $environment = $payment->booking?->is_test ? CredentialEnvironment::Test : CredentialEnvironment::Live;
        $credential = $this->credentials->find(IntegrationProvider::Viva, $environment);

        if (! $credential instanceof IntegrationCredential) {
            return RefundResult::failure('401');
        }

        // **The transaction, never the order** (2026-09-25). Viva's refund
        // endpoint takes the charge's TransactionId; `gateway_ref` holds the
        // 16-digit OrderCode the checkout was opened with, and every refund
        // sent with it was declined. The id is stored when the payment is
        // confirmed; a charge confirmed before that is looked up by its order.
        $transactionId = $payment->gateway_transaction_ref ?? $this->chargedTransactionId($credential, $payment);

        if ($transactionId === null) {
            return RefundResult::failure('404');
        }

        try {
            $response = $this->call(
                fn (): Response => $this->http
                    ->withToken($this->accessToken($credential, $environment))
                    ->timeout((int) config('kaiki.payments.timeout_seconds'))
                    ->delete(sprintf(
                        '%s/api/transactions/%s?amount=%d',
                        $this->apiHost($environment),
                        $transactionId,
                        (int) $amount->getMinorAmount()->toInt(),
                    )),
            );
        } catch (GatewayCallFailed $failed) {
            return RefundResult::failure($failed->description->code ?? '0');
        }

        $body = $response->json();
        $reference = is_array($body) ? ($body['TransactionId'] ?? null) : null;

        if (! is_string($reference)) {
            return RefundResult::failure('0');
        }

        return RefundResult::success((int) $amount->getMinorAmount()->toInt(), $reference);
    }

    /** The successful charge against this payment's order, asked of Viva. */
    private function chargedTransactionId(IntegrationCredential $credential, Payment $payment): ?string
    {
        if ($payment->gateway_ref === null || $payment->gateway_ref === '') {
            return null;
        }

        try {
            $transaction = $this->transactionFor($credential, $payment->gateway_ref);
        } catch (GatewayCallFailed) {
            return null;
        }

        return $transaction?->succeeded === true ? $transaction->transactionId : null;
    }

    public function describeError(string $code): TranslatableMessage
    {
        return GatewayErrorDictionary::describe(PaymentGatewayName::Viva, $code);
    }

    /**
     * An OAuth2 token, cached for slightly less than its own lifetime.
     *
     * The round trip Stripe does not have. Minting one per checkout would
     * double the latency of the slowest step in a booking, and the token is not
     * a credential we chose — it is one Viva issued and will reissue, so the
     * cache is a performance decision rather than a storage one.
     *
     * **Cached under `Cache`, never `Redis::`** (ENV-7), and keyed per tenant
     * and environment so a sandbox token can never be presented to the live
     * host.
     */
    private function accessToken(IntegrationCredential $credential, CredentialEnvironment $environment): string
    {
        $key = sprintf('kaiki:viva:token:%d:%s', $credential->tenant_id, $environment->value);

        $token = Cache::get($key);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $response = $this->call(
            fn (): Response => $this->http
                ->withBasicAuth(
                    (string) ($credential->credentials['client_id'] ?? ''),
                    (string) ($credential->credentials['client_secret'] ?? ''),
                )
                ->asForm()
                ->timeout((int) config('kaiki.payments.timeout_seconds'))
                ->post($this->accountsHost($environment) . '/connect/token', ['grant_type' => 'client_credentials']),
        );

        $body = $response->json();
        $token = is_array($body) ? ($body['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw GatewayCallFailed::forCode(PaymentGatewayName::Viva, '401');
        }

        // A minute short of Viva's own expiry, so a token cannot be presented
        // in the instant after it dies.
        $lifetime = is_array($body) && is_int($body['expires_in'] ?? null) ? $body['expires_in'] : 3600;

        Cache::put($key, $token, max(60, $lifetime - 60));

        return $token;
    }

    /** @param  callable(): Response  $send */
    private function call(callable $send): Response
    {
        try {
            $response = $send();
        } catch (Throwable $exception) {
            // The class and the tenant, never the request: an HTTP client's
            // exception message carries the authenticated call it made
            // (SEC-9, MYD-15).
            Log::warning('payments.gateway_unreachable', [
                'gateway' => PaymentGatewayName::Viva->value,
                'tenant_id' => Tenancy::id(),
                'exception' => $exception::class,
            ]);

            throw GatewayCallFailed::unreachable(PaymentGatewayName::Viva);
        }

        if ($response->failed()) {
            $body = $response->json();
            $code = is_array($body) ? ($body['ErrorCode'] ?? $body['error'] ?? null) : null;

            throw GatewayCallFailed::forCode(
                PaymentGatewayName::Viva,
                is_scalar($code) ? (string) $code : (string) $response->status(),
            );
        }

        return $response;
    }

    private function credentialFor(CredentialEnvironment $environment): IntegrationCredential
    {
        $credential = $this->credentials->find(IntegrationProvider::Viva, $environment);

        if (! $credential instanceof IntegrationCredential || ! $credential->isUsable()) {
            throw GatewayCallFailed::forCode(PaymentGatewayName::Viva, '401');
        }

        return $credential;
    }

    /**
     * Viva uses **separate hosts** for sandbox and live, not separate keys.
     *
     * Which makes an environment mix-up a request to the wrong server rather
     * than a rejected credential — a clearer failure, and the reason these are
     * three config values rather than one with a path.
     */
    /**
     * The verification key, fetched from Viva rather than asked of the operator.
     *
     * This is the field nobody could find, and it is still not a field: the key
     * is read from Viva with the Merchant ID and API key the operator copies off
     * the same dashboard page as everything else. It was briefly believed to be
     * readable with the OAuth2 token the client credentials mint — it is not,
     * and probing every plausible address on 2026-09-16 settled it. What the
     * operator never has to do is make this call by hand, which was the point.
     *
     * Stored on the credential once fetched, because {@see self::verifyWebhook()}
     * compares against `webhook_secret` and nothing else: the fetch is how the
     * column gets filled, not a second mechanism beside it. A key an operator
     * pasted by hand is left alone — if they have one, they are ahead of us.
     *
     * The path is configuration. `docs/api.md` records the exact verification
     * mechanism as an open question against Viva's live documentation (item 12),
     * and a path in a config file is a value that can be corrected without a
     * deploy when that question is finally answered.
     *
     * @throws GatewayCallFailed when Viva refuses, so the caller can say so
     */
    public function webhookVerificationKey(IntegrationCredential $credential): string
    {
        $stored = $credential->webhook_secret;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return $this->fetchWebhookVerificationKey($credential);
    }

    /**
     * Prove both credential pairs, for the verify button (PAY-4).
     *
     * Viva takes two of them — OAuth2 client credentials for orders, Basic auth
     * for the webhook key — and a check that exercised only one would tell an
     * operator their keys work when half of them are wrong. Nothing is returned:
     * the answer is whether this throws.
     *
     * @throws GatewayCallFailed when either pair is refused
     */
    public function checkCredentials(IntegrationCredential $credential): void
    {
        $this->accessToken($credential, $credential->environment);

        // Freshly, not `webhookVerificationKey()` — that one short-circuits on a
        // stored key, so after the first success it would stop checking the pair
        // it is here to check.
        $this->fetchWebhookVerificationKey($credential);
    }

    /**
     * Ask Viva what actually happened to an order (`docs/api.md` item 12).
     *
     * `GET {checkout host}/api/transactions?ordercode=…` with the Basic pair —
     * the same authentication split as the webhook key, and the reason both live
     * on the checkout host rather than the api one. The OAuth2 equivalents 404;
     * this was established by probing on 2026-09-16, when a real demo payment
     * succeeded at Viva and no webhook ever arrived.
     *
     * Viva answers with every transaction against the order, so a card that was
     * declined and then retried successfully has two. The successful one wins:
     * what matters is whether the money is there now.
     *
     * `StatusId` is a single letter. `F` is finished, which is the only one that
     * means paid. `E` (error), `X` (rejected) and `C` (cancelled) are over and
     * unpaid. Anything else — `A` above all, the guest sitting on the 3-D Secure
     * step — is not settled, and the caller must wait rather than guess.
     */
    public function transactionFor(IntegrationCredential $credential, string $reference): ?GatewayTransaction
    {
        if (trim($reference) === '') {
            return null;
        }

        $response = $this->call(
            fn (): Response => $this->http
                ->withBasicAuth(
                    (string) ($credential->credentials['merchant_id'] ?? ''),
                    (string) ($credential->credentials['api_key'] ?? ''),
                )
                ->timeout((int) config('kaiki.payments.timeout_seconds'))
                ->get($this->checkoutHost($credential->environment) . '/api/transactions', ['ordercode' => $reference]),
        );

        $body = $response->json();
        $transactions = is_array($body) ? ($body['Transactions'] ?? null) : null;

        if (! is_array($transactions) || $transactions === []) {
            // The order exists and nothing has been attempted against it, or
            // Viva has never heard of it. Either way there is nothing to act on,
            // and "no answer" must never read as "unpaid".
            return null;
        }

        $latest = null;

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $status = strtoupper((string) ($transaction['StatusId'] ?? ''));
            $amount = self::centsFrom($transaction['Amount'] ?? null);

            if ($status === 'F') {
                // A success ends the search: money present is the answer
                // whatever else was attempted against this order.
                $id = $transaction['TransactionId'] ?? null;

                return GatewayTransaction::paid($amount, is_string($id) && $id !== '' ? $id : null);
            }

            $latest ??= in_array($status, ['E', 'X', 'C'], true)
                ? GatewayTransaction::failed($amount)
                : GatewayTransaction::pending($amount);
        }

        return $latest;
    }

    /**
     * Viva quotes money as a decimal number of euros; everything here is cents.
     *
     * Through a string, because `(int) (110.00 * 100)` is 10999 on a binary
     * float often enough to matter, and this number decides whether a booking is
     * confirmed for the right amount.
     */
    private static function centsFrom(mixed $amount): int
    {
        if (! is_numeric($amount)) {
            return 0;
        }

        return (int) round((float) sprintf('%.2F', (float) $amount) * 100);
    }

    private function fetchWebhookVerificationKey(IntegrationCredential $credential): string
    {
        $environment = $credential->environment;

        // **Basic auth on the checkout host**, not the bearer token on the api
        // host that the rest of this class uses. Viva splits its APIs across two
        // schemes and this endpoint is on the other side of the split: probing
        // it on 2026-09-16 gave 401 for a bearer token, 401 anonymous — so the
        // path is real and wants the Merchant ID / API key pair — and 404 for
        // every address on `api.`, so there is no OAuth2 equivalent to reach.
        $response = $this->call(
            fn (): Response => $this->http
                ->withBasicAuth(
                    (string) ($credential->credentials['merchant_id'] ?? ''),
                    (string) ($credential->credentials['api_key'] ?? ''),
                )
                ->timeout((int) config('kaiki.payments.timeout_seconds'))
                ->get($this->checkoutHost($environment) . (string) config('kaiki.payments.viva.webhook_key_path')),
        );

        $body = $response->json();

        // Viva's own samples show a lower-case `key`; their webhook guide prints
        // `Key`. Both are read rather than guessing which generation of the API
        // this merchant's account is on.
        $key = is_array($body) ? ($body['Key'] ?? $body['key'] ?? null) : null;

        if (! is_string($key) || $key === '') {
            throw GatewayCallFailed::forCode(PaymentGatewayName::Viva, '404');
        }

        Tenancy::withoutTenancy(static function () use ($credential, $key): void {
            $credential->forceFill(['webhook_secret' => $key])->save();
        });

        return $key;
    }

    private function apiHost(CredentialEnvironment $environment): string
    {
        return $this->host('api', $environment);
    }

    private function accountsHost(CredentialEnvironment $environment): string
    {
        return $this->host('accounts', $environment);
    }

    private function checkoutHost(CredentialEnvironment $environment): string
    {
        return $this->host('checkout', $environment);
    }

    private function host(string $which, CredentialEnvironment $environment): string
    {
        return rtrim((string) config(sprintf(
            'kaiki.payments.viva.%s.%s',
            $environment->isTest() ? 'demo' : 'live',
            $which,
        )), '/');
    }
}
