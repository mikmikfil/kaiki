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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe Checkout, on the **operator's own account** (spec PAY-1, PAY-2).
 *
 * ## No Stripe Connect, and that is a decision rather than an omission
 *
 * PAY-1, FIXED: guests pay the operator directly. There is no platform balance,
 * no application fee and no payout — the operator's own `sk_` key from
 * `integration_credentials` (#79) is what authenticates every call here, and
 * money moves between the guest and the operator with Kaiki never in the
 * middle. ADR-0004 rejected Connect on scope grounds and Viva has no equivalent
 * anyway, which would have broken the single contract.
 *
 * ## The idempotency key is ours and travels with the request
 *
 * PAY-9. Stripe honours `Idempotency-Key`, so the key minted before the
 * redirect goes with the call — a retried job reaching Stripe twice gets the
 * *same session* back rather than creating a second one. Where a gateway does
 * not support this (Viva does not), the local unique index is the only dedupe
 * there is, which is why it exists in both cases.
 *
 * ## The webhook signature is verified on the raw body, before parsing
 *
 * PAY-6. Stripe signs `{timestamp}.{payload}` with HMAC-SHA256 and sends both
 * in `Stripe-Signature`. Two things matter and both are easy to get wrong:
 * the comparison is `hash_equals` (a timing side channel leaks the secret byte
 * by byte), and the **timestamp is checked** — a valid signature over an old
 * payload is a replay, and a signature check that ignores the timestamp accepts
 * one forever.
 *
 * ## No endpoint is written into this class
 *
 * `CLAUDE.md`: gateway endpoints are never hardcoded. They come from
 * `config('kaiki.payments.stripe')`, which is also how the test suite points
 * every call at a host that cannot resolve.
 */
final class StripeCheckoutGateway implements PaymentGateway
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CredentialRepository $credentials,
    ) {}

    public function createCheckoutSession(Booking $booking, PaymentKind $kind, Money $amount): RedirectTarget
    {
        $credential = $this->credentialFor($booking);
        $payment = $this->pendingPaymentFor($booking, $kind);

        $response = $this->call(
            fn () => $this->http
                ->withToken($credential->credentials['secret_key'] ?? '')
                // PAY-9: ours, minted before the redirect, sent to the gateway.
                ->withHeaders(['Idempotency-Key' => $payment === null ? $booking->uuid : $payment->idempotency_key])
                ->asForm()
                ->timeout((int) config('kaiki.payments.timeout_seconds'))
                ->post(rtrim((string) config('kaiki.payments.stripe.base_uri'), '/') . '/v1/checkout/sessions', [
                    'mode' => 'payment',
                    'success_url' => $this->returnUrl($booking, 'success'),
                    'cancel_url' => $this->returnUrl($booking, 'cancel'),
                    'client_reference_id' => $booking->uuid,
                    'line_items[0][price_data][currency]' => strtolower($amount->getCurrency()->getCurrencyCode()),
                    'line_items[0][price_data][unit_amount]' => (int) $amount->getMinorAmount()->toInt(),
                    // The product name a guest sees on Stripe's page, in *their*
                    // language — the booking's locale drives every guest-facing
                    // surface for its whole life (§2.5).
                    'line_items[0][price_data][product_data][name]' => (string) trans(
                        'payments.checkout.line_item',
                        ['reference' => $booking->reference],
                        $booking->locale,
                    ),
                    'line_items[0][quantity]' => 1,
                    'metadata[booking_uuid]' => $booking->uuid,
                    'metadata[payment_kind]' => $kind->value,
                ]),
        );

        $body = $response->json();

        if (! is_array($body) || ! is_string($body['id'] ?? null) || ! is_string($body['url'] ?? null)) {
            throw GatewayCallFailed::forCode(PaymentGatewayName::Stripe, 'processing_error');
        }

        return new RedirectTarget(
            url: $body['url'],
            reference: $body['id'],
            context: ['payment_intent' => $body['payment_intent'] ?? null],
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = $this->webhookSecretFor($request);

        if ($secret === null || $secret === '') {
            // No secret means nothing can be verified, and an unverifiable
            // webhook is refused rather than trusted (PAY-5, PAY-7).
            return false;
        }

        $header = (string) $request->header('Stripe-Signature', '');
        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);

            if (is_string($key) && is_string($value)) {
                $parts[$key] = $value;
            }
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;

        if (! is_string($timestamp) || ! is_string($signature) || ! ctype_digit($timestamp)) {
            return false;
        }

        // **The timestamp check is not optional.** A valid signature over an old
        // payload is a replay, and a verifier that only compares the HMAC
        // accepts one forever.
        if (abs(time() - (int) $timestamp) > (int) config('kaiki.payments.webhook_tolerance_seconds')) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        // Constant time. A `===` here leaks the secret one byte at a time to
        // anybody willing to send a few million requests.
        return hash_equals($expected, $signature);
    }

    public function refund(Payment $payment, Money $amount): RefundResult
    {
        $credential = $this->credentials->find(IntegrationProvider::Stripe, $this->environmentFor($payment));

        if (! $credential instanceof IntegrationCredential) {
            return RefundResult::failure('api_key_expired');
        }

        try {
            $response = $this->call(
                fn () => $this->http
                    ->withToken($credential->credentials['secret_key'] ?? '')
                    ->asForm()
                    ->timeout((int) config('kaiki.payments.timeout_seconds'))
                    ->post(rtrim((string) config('kaiki.payments.stripe.base_uri'), '/') . '/v1/refunds', [
                        'payment_intent' => $payment->gateway_transaction_ref ?? $payment->gateway_ref,
                        'amount' => (int) $amount->getMinorAmount()->toInt(),
                    ]),
            );
        } catch (GatewayCallFailed $failed) {
            // A refused refund is an answer the operator needs, not a 500 in
            // the middle of a cancellation workflow.
            return RefundResult::failure($failed->description->code ?? 'processing_error');
        }

        $body = $response->json();

        if (! is_array($body) || ! is_string($body['id'] ?? null)) {
            return RefundResult::failure('processing_error');
        }

        return RefundResult::success((int) $amount->getMinorAmount()->toInt(), $body['id']);
    }

    public function describeError(string $code): TranslatableMessage
    {
        return GatewayErrorDictionary::describe(PaymentGatewayName::Stripe, $code);
    }

    /**
     * One call, retried with backoff, logged without a credential (EXT-2, SEC-9).
     *
     * The log line carries the tenant and the exception class and nothing else.
     * An HTTP client's exception message routinely contains the request it made,
     * and the request it made is authenticated — which is exactly what MYD-15
     * and SEC-9 forbid putting in a log.
     *
     * @param  callable(): Response  $send
     */
    private function call(callable $send): Response
    {
        try {
            $response = $send();
        } catch (Throwable $exception) {
            Log::warning('payments.gateway_unreachable', [
                'gateway' => PaymentGatewayName::Stripe->value,
                'tenant_id' => Tenancy::id(),
                'exception' => $exception::class,
            ]);

            throw GatewayCallFailed::unreachable(PaymentGatewayName::Stripe);
        }

        if ($response->failed()) {
            $body = $response->json();
            $code = is_array($body) ? ($body['error']['code'] ?? $body['error']['type'] ?? null) : null;

            throw GatewayCallFailed::forCode(
                PaymentGatewayName::Stripe,
                is_string($code) ? $code : 'processing_error',
            );
        }

        return $response;
    }

    private function credentialFor(Booking $booking): IntegrationCredential
    {
        $credential = $this->credentials->find(
            IntegrationProvider::Stripe,
            $booking->is_test ? CredentialEnvironment::Test : CredentialEnvironment::Live,
        );

        if (! $credential instanceof IntegrationCredential || ! $credential->isUsable()) {
            // The operator's own keys are missing or unverified. The guest is
            // told something temporary; the operator is told what is actually
            // wrong (PAY-12).
            throw GatewayCallFailed::forCode(PaymentGatewayName::Stripe, 'api_key_expired');
        }

        return $credential;
    }

    /** The `pending` row minted before this call (PAY-8, PAY-9). */
    private function pendingPaymentFor(Booking $booking, PaymentKind $kind): ?Payment
    {
        return Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('kind', $kind->value)
            ->open()
            ->latest('id')
            ->first();
    }

    private function environmentFor(Payment $payment): CredentialEnvironment
    {
        return $payment->booking?->is_test ? CredentialEnvironment::Test : CredentialEnvironment::Live;
    }

    /**
     * The signing secret, resolved without a tenant in context.
     *
     * A webhook arrives before tenancy is resolved — which is what
     * `integration_credentials.external_account_id` and its not-tenant-first
     * index exist for (#79).
     */
    private function webhookSecretFor(Request $request): ?string
    {
        $account = (string) $request->input('account', '');

        $credential = $account === ''
            ? $this->credentials->find(IntegrationProvider::Stripe, CredentialEnvironment::Live)
            : IntegrationCredential::findByExternalAccount(IntegrationProvider::Stripe, $account);

        return $credential?->webhook_secret;
    }

    /** Where Stripe sends the guest back to. Always our page, never a gateway URL. */
    private function returnUrl(Booking $booking, string $outcome): string
    {
        return rtrim((string) config('app.url'), '/') . '/b/' . $booking->manage_token . '?payment=' . $outcome;
    }
}
