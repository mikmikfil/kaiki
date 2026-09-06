<?php

declare(strict_types=1);

namespace App\Domain\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Domain\Integrations\Support\CredentialRepository;
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
final class VivaSmartCheckoutGateway implements PaymentGateway
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
    public function verifyWebhook(Request $request): bool
    {
        $presented = (string) $request->header('X-Viva-Verification', '');

        if ($presented === '') {
            return false;
        }

        $sourceCode = (string) data_get($request->all(), 'EventData.SourceCode', '');

        $credential = $sourceCode === ''
            ? $this->credentials->find(IntegrationProvider::Viva, CredentialEnvironment::Live)
            : IntegrationCredential::findByExternalAccount(IntegrationProvider::Viva, $sourceCode);

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

        try {
            $response = $this->call(
                fn (): Response => $this->http
                    ->withToken($this->accessToken($credential, $environment))
                    ->timeout((int) config('kaiki.payments.timeout_seconds'))
                    ->delete(sprintf(
                        '%s/api/transactions/%s?amount=%d',
                        $this->apiHost($environment),
                        $payment->gateway_transaction_ref ?? $payment->gateway_ref,
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
