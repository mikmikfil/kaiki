<?php

declare(strict_types=1);

namespace Tests\Support\Payments;

use App\Enums\IntegrationProvider;
use App\Enums\PaymentGatewayName;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\IntegrationCredential;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Http;

/**
 * A tenant with a booking at the gateway, and a signable payload for it.
 *
 * A class rather than Pest helper functions, because four test files need this
 * and a `function` in a Pest file is scoped to that file — the second file to
 * call it fails with "undefined function", which reads as a broken test rather
 * than as a missing import.
 *
 * ## The signature is computed, never stubbed
 *
 * Every webhook test's premise is that the endpoint verified something. A faked
 * verifier — a bound double, a config flag — would mean none of them proved it,
 * and the one that matters (an unsigned payload is refused) would pass against
 * an endpoint that verifies nothing at all.
 *
 * So {@see self::signedStripeHeaders()} computes a real HMAC over the exact
 * body being sent, with the same secret the credential row holds.
 */
final class WebhookScenario
{
    /** The signing secret the credential row is created with. */
    public const SECRET = 'whsec_test_secret';

    /** The Stripe account id the payload names, matching `external_account_id`. */
    public const ACCOUNT = 'acct_test';

    /** The session reference the payment is created with. */
    public const REFERENCE = 'cs_test_reference';

    /**
     * @param  int  $capacity  seats on the departure, for the BKG-12 cases
     * @return array{0: Tenant, 1: Booking, 2: Payment}
     */
    public static function make(int $capacity = 10, int $totalCents = 12000): array
    {
        $tenant = Tenant::factory()->create();

        [$booking, $payment] = Tenancy::forTenant($tenant, static function () use ($capacity, $totalCents): array {
            // The credential the verifier resolves the signing secret from. The
            // payload carries `account`, which matches `external_account_id` —
            // #79's deliberately not-tenant-first index, and the whole reason a
            // webhook can be authenticated before tenancy exists.
            IntegrationCredential::factory()
                ->forProvider(IntegrationProvider::Stripe)
                ->live()
                ->verified()
                ->default()
                ->create([
                    'external_account_id' => self::ACCOUNT,
                    'webhook_secret' => self::SECRET,
                ]);

            $departure = Departure::factory()->create([
                'capacity' => $capacity,
                // Two committed, because BKG-9 moved them at redirect — which
                // is the state a webhook actually arrives into.
                'seats_sold' => 2,
                'seats_held' => 0,
                'min_pax' => 0,
            ]);

            $booking = Booking::factory()
                ->forDeparture($departure)
                ->withPax(2, 2)
                ->pendingPayment()
                ->create([
                    'total_cents' => $totalCents,
                    'paid_cents' => 0,
                    'balance_cents' => $totalCents,
                ]);

            $payment = Payment::factory()->pending()->create([
                'booking_id' => $booking->getKey(),
                'amount_cents' => $totalCents,
                'gateway' => PaymentGatewayName::Stripe,
                'gateway_ref' => self::REFERENCE,
            ]);

            return [$booking, $payment];
        });

        return [$tenant, $booking, $payment];
    }

    /**
     * The recorded responses both gateways give for a checkout session.
     *
     * Every test that reaches a real gateway needs these. Kept here rather than
     * copied per file because the one that forgets does not fail loudly — it
     * makes a network call, which passes on a laptop with internet and fails on
     * a CI runner with egress blocked, or worse, reaches a real payment
     * provider.
     *
     * The hosts are pinned to `.test` by `phpunit.xml` as well, so a request
     * escaping this fake cannot resolve either. Two layers, because this one is
     * a thing a test can omit.
     */
    public static function fakeGatewayResponses(): void
    {
        Http::fake([
            // Viva: an OAuth2 token, then an order code — never a URL, which
            // the gateway class builds itself.
            '*accounts*/connect/token' => Http::response(['access_token' => 'tok_test', 'expires_in' => 3600]),
            '*/checkout/v2/orders' => Http::response(['orderCode' => 1234567890123456]),
            '*/api/transactions/*' => Http::response(['TransactionId' => 'viva_re_test']),

            // Stripe: a session id and a hosted URL.
            '*/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_a1b2c3',
                'url' => 'https://checkout.stripe.test/pay/cs_test_a1b2c3',
                'payment_intent' => 'pi_test_a1b2c3',
            ]),
            '*/v1/refunds' => Http::response(['id' => 're_test_a1b2c3']),
        ]);
    }

    /**
     * A Stripe success payload for the scenario's payment.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function stripeSuccess(string $eventId = 'evt_test_1', array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            // Resolves the credential, and therefore the signing secret, with
            // no tenant in context.
            'account' => self::ACCOUNT,
            'data' => ['object' => ['id' => self::REFERENCE]],
        ], $overrides);
    }

    /**
     * A genuine signature over the exact body being sent.
     *
     * `{timestamp}.{payload}` HMAC-SHA256, which is Stripe's scheme. The
     * timestamp is *now* by default; a test that wants to prove the replay
     * window passes an old one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function signedStripeHeaders(
        array $payload,
        string $secret = self::SECRET,
        ?int $timestamp = null,
    ): array {
        $timestamp ??= time();
        $body = (string) json_encode($payload);

        return [
            'Stripe-Signature' => 't=' . $timestamp
                . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
        ];
    }
}
