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
 * A tenant with a booking at the gateway, and a verifiable payload for it.
 *
 * A class rather than Pest helper functions, because four test files need this
 * and a `function` in a Pest file is scoped to that file — the second file to
 * call it fails with "undefined function", which reads as a broken test rather
 * than as a missing import.
 *
 * ## The verification is real, never stubbed
 *
 * Every webhook test's premise is that the endpoint verified something. A faked
 * verifier — a bound double, a config flag — would mean none of them proved it,
 * and the one that matters (an unverified payload is refused) would pass
 * against an endpoint that verifies nothing at all.
 *
 * So {@see self::verifiedHeaders()} presents the same key the credential row
 * holds, and the endpoint compares it in constant time exactly as in
 * production.
 *
 * ## Viva's scheme, which is weaker than an HMAC and is the one that exists
 *
 * This harness signed a Stripe-shaped payload until Stripe was removed. Viva
 * does not sign at all: it presents a **verification key** in a header and the
 * receiver checks it holds the same one. There is no body signature, so nothing
 * is tamper-evident and there is no timestamp to bound a replay with — which is
 * exactly why `gateway_webhook_events` carries a unique event id, and why the
 * idempotency tests matter more here than they would have against an HMAC.
 *
 * The tenant is resolved from `EventData.SourceCode` against
 * `integration_credentials.external_account_id` — #79's deliberately
 * not-tenant-first index, and the whole reason a webhook can be authenticated
 * before tenancy exists.
 */
final class WebhookScenario
{
    /** The verification key the credential row is created with. */
    public const SECRET = 'viva_verification_key_test';

    /** The Viva source code the payload names, matching `external_account_id`. */
    public const ACCOUNT = 'src_test';

    /** The order code the payment is created with. */
    public const REFERENCE = '1234567890123456';

    /**
     * @param  int  $capacity  seats on the departure, for the BKG-12 cases
     * @return array{0: Tenant, 1: Booking, 2: Payment}
     */
    public static function make(int $capacity = 10, int $totalCents = 12000): array
    {
        $tenant = Tenant::factory()->create();

        [$booking, $payment] = Tenancy::forTenant($tenant, static function () use ($capacity, $totalCents): array {
            // The credential the verifier resolves the key from. The payload
            // carries `EventData.SourceCode`, which matches
            // `external_account_id`.
            IntegrationCredential::factory()
                ->forProvider(IntegrationProvider::Viva)
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
                'gateway' => PaymentGatewayName::Viva,
                'gateway_ref' => self::REFERENCE,
            ]);

            return [$booking, $payment];
        });

        return [$tenant, $booking, $payment];
    }

    /**
     * The recorded responses the gateway gives for a checkout session.
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
        ]);
    }

    /**
     * A Viva success payload for the scenario's payment.
     *
     * `EventTypeId` 1796 is a successful transaction and 1798 a failed one —
     * numbers rather than words, which is the difference the error dictionary
     * and the outcome `match` both accommodate.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function gatewaySuccess(string $eventId = 'evt_test_1', array $overrides = []): array
    {
        return array_replace_recursive([
            'EventTypeId' => 1796,
            'EventData' => [
                // Viva sends no event id of its own — the controller derives
                // one from the order code and the event type. A test that wants
                // two *different* events overrides the order code rather than
                // an id, which is the real shape of the idempotency question.
                'OrderCode' => self::REFERENCE,
                // Resolves the credential, and therefore the verification key,
                // with no tenant in context.
                'SourceCode' => self::ACCOUNT,
                'TransactionId' => $eventId,
            ],
        ], $overrides);
    }

    /**
     * A failed-transaction payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function gatewayFailure(string $eventId = 'evt_failed', array $overrides = []): array
    {
        return self::gatewaySuccess($eventId, array_replace_recursive([
            'EventTypeId' => 1798,
        ], $overrides));
    }

    /**
     * The `event_id` the controller will derive from this payload.
     *
     * Viva sends no event id, so `GatewayWebhookController::eventIdFor()` builds
     * one from the order code and the event type. A test that wants to find its
     * own row has to ask the same question the controller asked — looking up a
     * label the payload merely *carried* finds nothing, which fails as
     * `ModelNotFoundException` and reads as a broken endpoint rather than as a
     * test looking in the wrong place.
     *
     * Duplicated from the controller on purpose: a test that imported the
     * production helper would pass even if that helper started returning a
     * constant.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function derivedEventId(array $payload): string
    {
        return implode(':', array_filter([
            (string) data_get($payload, 'EventData.OrderCode', ''),
            (string) ($payload['EventTypeId'] ?? ''),
        ]));
    }

    /**
     * The header a genuine Viva callback carries.
     *
     * There is no body signature to compute: Viva presents the verification key
     * the receiver already holds, and the check is a constant-time comparison.
     * A test that wants a refusal passes a different secret.
     *
     * @param  array<string, mixed>  $payload  unused; kept so call sites read as they did
     * @return array<string, string>
     */
    public static function verifiedHeaders(array $payload = [], string $secret = self::SECRET): array
    {
        return ['X-Viva-Verification' => $secret];
    }
}
