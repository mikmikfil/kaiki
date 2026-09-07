<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Domain\Integrations\Support\CredentialRepository;
use App\Domain\Payments\Data\RedirectTarget;
use App\Domain\Payments\Data\RefundResult;
use App\Domain\Payments\Data\TranslatableMessage;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Gateways\GatewayCallFailed;
use App\Domain\Payments\Gateways\StripeCheckoutGateway;
use App\Domain\Payments\Gateways\VivaSmartCheckoutGateway;
use App\Domain\Payments\Support\GatewayResolver;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentKind;
use App\Models\Booking;
use App\Models\IntegrationCredential;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Brick\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| One contract test, run against all three implementations
|--------------------------------------------------------------------------
|
| ADR-0004 fixes `PaymentGateway` at four methods. This is the test that makes
| that fixed shape mean something: the same assertions run against Viva, Stripe
| **and the fake**, so the fake cannot quietly diverge.
|
| That last part is the point. #81 confirms bookings through the fake — it is
| what lets the AVL-44 overselling guarantee be tested without a network — so a
| fake that behaved differently from the real gateways would make that whole
| guarantee a test of nothing.
|
| **No test here makes a network call.** Both real gateways are driven against
| recorded fixtures through `Http::fake()`, and a separate test asserts that
| nothing in the suite is pointed at a resolvable host.
|
*/

/** @return array<string, array{0: class-string<PaymentGateway>}> */
dataset('gateways', [
    'fake' => [FakeGateway::class],
    'viva' => [VivaSmartCheckoutGateway::class],
    'stripe' => [StripeCheckoutGateway::class],
]);

/**
 * A tenant with usable credentials for both gateways, and one bookable booking.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function gatewayScenario(): array
{
    $tenant = Tenant::factory()->create();

    $booking = Tenancy::forTenant($tenant, static function (): Booking {
        foreach ([IntegrationProvider::Viva, IntegrationProvider::Stripe] as $provider) {
            IntegrationCredential::factory()
                ->forProvider($provider)
                ->live()
                ->verified()
                ->create(['is_default' => $provider === IntegrationProvider::Viva]);
        }

        $booking = Booking::factory()->create([
            'total_cents' => 12000,
            'balance_cents' => 12000,
            'paid_cents' => 0,
            'is_test' => false,
        ]);

        Payment::factory()->pending()->create(['booking_id' => $booking->getKey(), 'amount_cents' => 12000]);

        return $booking;
    });

    return [$tenant, $booking];
}

/** The happy-path response each real gateway returns for a checkout session. */
function fakeCheckoutResponses(): void
{
    Http::fake([
        // Viva: an OAuth2 token, then an order code. Never a URL — the gateway
        // class builds that, which is the difference the contract hides.
        '*accounts*/connect/token' => Http::response(['access_token' => 'tok_test', 'expires_in' => 3600]),
        '*/checkout/v2/orders' => Http::response(['orderCode' => 1234567890123456]),
        // Stripe: a session id and a hosted URL.
        '*/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_a1b2c3',
            'url' => 'https://checkout.stripe.test/pay/cs_test_a1b2c3',
            'payment_intent' => 'pi_test_a1b2c3',
        ]),
        '*/v1/refunds' => Http::response(['id' => 're_test_a1b2c3']),
        '*/api/transactions/*' => Http::response(['TransactionId' => 'viva_re_test']),
    ]);
}

it('returns a redirect target with both a url and a reference', function (string $class): void {
    fakeCheckoutResponses();

    [$tenant, $booking] = gatewayScenario();

    Tenancy::forTenant($tenant, function () use ($class, $booking): void {
        /** @var PaymentGateway $gateway */
        $gateway = app($class);

        $target = $gateway->createCheckoutSession($booking, PaymentKind::Full, Money::ofMinor(12000, 'EUR'));

        // Both halves matter and the gateways supply them differently: Stripe
        // returns a URL, Viva returns an order code and the class builds one.
        // Without the reference a webhook cannot find its payment; without the
        // URL there is nowhere to send anybody.
        expect($target)->toBeInstanceOf(RedirectTarget::class)
            // Absolute, because it is handed to a browser as-is. Not
            // necessarily `https://`: the fake now points at our own origin
            // (issue 111), and in a test that origin is `http://localhost`.
            ->and($target->url)->toMatch('#^https?://#')
            ->and($target->reference)->not->toBeEmpty();
    });
})->with('gateways')->group('fast');

it('never points a guest at a stranger during a test', function (string $class): void {
    fakeCheckoutResponses();

    [$tenant, $booking] = gatewayScenario();

    Tenancy::forTenant($tenant, function () use ($class, $booking): void {
        $target = app($class)->createCheckoutSession($booking, PaymentKind::Full, Money::ofMinor(12000, 'EUR'));

        // The acceptance criterion: no test makes a network call. `Http::fake()`
        // covers the outbound side; this covers the side a browser would follow
        // if a fixture ever leaked into one.
        //
        // Two ways to be safe, and **issue 111 added the second**. The external
        // gateways point at `.test`, reserved by RFC 6761 and unable to resolve.
        // The fake now points at the sandbox checkout page on **our own
        // origin** — which is not weaker: a browser following it reaches a page
        // that refuses everything but a test booking, rather than a DNS error.
        $host = (string) parse_url($target->url, PHP_URL_HOST);

        expect(str_ends_with($host, '.test') || $host === parse_url((string) config('app.url'), PHP_URL_HOST))
            ->toBeTrue("A checkout URL pointed at {$host}, which is neither ours nor unresolvable.");
    });
})->with('gateways')->group('fast');

it('describes a known error in four sentences across two audiences', function (string $class): void {
    /** @var PaymentGateway $gateway */
    $gateway = app($class);

    // Every implementation knows at least one code. Viva's are numeric and
    // Stripe's are words, which is precisely why the dictionaries are per
    // gateway and the *shape* is what this test asserts.
    $code = $class === VivaSmartCheckoutGateway::class ? '2' : 'card_declined';

    $message = $gateway->describeError($code);

    expect($message)->toBeInstanceOf(TranslatableMessage::class)
        ->and($message->isUnmapped)->toBeFalse()
        // Four real sentences, none of them a lang key that failed to resolve.
        ->and($message->guestEl)->not->toContain('payments.')
        ->and($message->guestEn)->not->toContain('payments.')
        ->and($message->operatorEl)->not->toContain('payments.')
        ->and($message->operatorEn)->not->toContain('payments.')
        // PAY-12: the guest is never shown the gateway's code.
        ->and($message->guestEl)->not->toContain($code)
        ->and($message->guestEn)->not->toContain($code);
})->with('gateways')->group('fast');

it('degrades an unmapped code without telling the guest about it', function (string $class): void {
    $message = app($class)->describeError('a_code_no_gateway_has_ever_returned');

    expect($message->isUnmapped)->toBeTrue()
        // The guest gets the ordinary sentence — never nothing, never the code.
        ->and($message->guestEn)->toBe((string) trans('payments.guest.generic', [], 'en'))
        ->and($message->guestEn)->not->toContain('a_code_no_gateway')
        // The operator gets the code, marked unrecognised, because they are the
        // only person who can report it.
        ->and($message->operatorEn)->toContain('a_code_no_gateway');
})->with('gateways')->group('fast');

it('refunds and reports the amount it moved', function (string $class): void {
    fakeCheckoutResponses();

    [$tenant, $booking] = gatewayScenario();

    Tenancy::forTenant($tenant, function () use ($class, $booking): void {
        $payment = Payment::factory()->create([
            'booking_id' => $booking->getKey(),
            'amount_cents' => 12000,
            'gateway_ref' => 'ref_test',
            'gateway_transaction_ref' => 'txn_test',
        ]);

        $result = app($class)->refund($payment, Money::ofMinor(4000, 'EUR'));

        expect($result)->toBeInstanceOf(RefundResult::class)
            ->and($result->succeeded)->toBeTrue()
            ->and($result->refundedCents)->toBe(4000)
            ->and($result->reference)->not->toBeEmpty();
    });
})->with('gateways')->group('fast');

it('refuses an unverifiable webhook rather than trusting it', function (string $class): void {
    // No signature, no secret, nothing. PAY-5 and PAY-7: an unverified webhook
    // is rejected. The fake is exempt — it verifies nothing by design, because a
    // fake signature algorithm is a second thing to keep in step for no benefit.
    $verified = app($class)->verifyWebhook(request());

    expect($verified)->toBe($class === FakeGateway::class);
})->with('gateways')->group('fast');

it('has exactly the four methods ADR-0004 fixed', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(PaymentGateway::class))->getMethods(),
    );

    sort($methods);

    // A fifth method is an ADR, not a commit. The reason is not minimalism:
    // Stripe supports card-on-file and Viva does not, so a wider contract would
    // have one implementation throwing on half its surface.
    expect($methods)->toBe(['createCheckoutSession', 'describeError', 'refund', 'verifyWebhook']);
})->group('fast');

it('reports a gateway that cannot be reached as unreachable, not as a decline', function (): void {
    Http::fake(fn () => throw new ConnectionException('nope'));

    [$tenant, $booking] = gatewayScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        try {
            app(StripeCheckoutGateway::class)->createCheckoutSession($booking, PaymentKind::Full, Money::ofMinor(12000, 'EUR'));

            expect(false)->toBeTrue('the unreachable gateway should have thrown');
        } catch (GatewayCallFailed $failed) {
            // A connection timeout produces no code to look up, so treating it
            // as an unmapped code would tell an operator their gateway returned
            // something unrecognised when it returned nothing at all.
            expect($failed->description->isUnmapped)->toBeFalse()
                ->and($failed->description->operatorEn)->toContain('could not reach')
                // And the guest is told something temporary — not that their
                // card failed, which would be a lie about their bank.
                ->and($failed->description->guestEn)->toBe((string) trans('payments.guest.temporary', [], 'en'));
        }
    });
})->group('fast');

it('refuses to build a session when the operator credentials are unusable', function (): void {
    $tenant = Tenant::factory()->create();

    $booking = Tenancy::forTenant($tenant, static function (): Booking {
        // Present but never verified — the state PAY-11 leans on `verified_at`
        // to catch, and the one that would otherwise turn a live checkout into
        // a 500 in front of a guest.
        IntegrationCredential::factory()->forProvider(IntegrationProvider::Stripe)->live()->create();

        return Booking::factory()->create(['is_test' => false]);
    });

    Tenancy::forTenant($tenant, function () use ($booking): void {
        try {
            app(StripeCheckoutGateway::class)->createCheckoutSession($booking, PaymentKind::Full, Money::ofMinor(1000, 'EUR'));

            expect(false)->toBeTrue('unusable credentials should have been refused');
        } catch (GatewayCallFailed $failed) {
            // The operator is told their key is the problem; the guest is told
            // something temporary, because it is not their card and telling
            // them it was would be false.
            expect($failed->description->operatorEn)->toContain('secret key')
                ->and($failed->description->guestEn)->toBe((string) trans('payments.guest.temporary', [], 'en'));
        }
    });
})->group('fast');

it('resolves credentials for the environment the booking is in, never one it was asked for', function (): void {
    // PAY-11: sandbox must be impossible to enable accidentally. There is no
    // parameter to pass — `bookings.is_test` decides, and it is written at
    // creation and never changes.
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        IntegrationCredential::factory()->forProvider(IntegrationProvider::Viva)->live()->verified()->default()->create();

        $live = Booking::factory()->create(['is_test' => false]);
        $test = Booking::factory()->create(['is_test' => true]);

        $resolver = app(GatewayResolver::class);

        expect($resolver->environmentFor($live))->toBe(CredentialEnvironment::Live)
            ->and($resolver->environmentFor($test))->toBe(CredentialEnvironment::Test)
            // A live booking with a live gateway resolves to the real one.
            ->and($resolver->forBooking($live))->toBeInstanceOf(VivaSmartCheckoutGateway::class)
            // A test booking with no test credentials falls back to the fake —
            // SAA-9's onboarding, and *only* in a sandbox.
            ->and($resolver->forBooking($test))->toBeInstanceOf(FakeGateway::class);
    });
})->group('fast');

it('refuses a live booking with no configured gateway rather than faking it', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create(['is_test' => false]);

        // Null, not the fake. A fake that quietly succeeded on a live booking
        // would confirm a trip nobody paid for.
        expect(app(GatewayResolver::class)->forBooking($booking))->toBeNull();
    });
})->group('fast');

it('reads no credential from the repository more than once per request', function (): void {
    fakeCheckoutResponses();

    [$tenant, $booking] = gatewayScenario();

    Tenancy::forTenant($tenant, function () use ($booking, $tenant): void {
        app(StripeCheckoutGateway::class)->createCheckoutSession($booking, PaymentKind::Full, Money::ofMinor(12000, 'EUR'));

        // #79's requirement, still true now that a gateway is the caller: the
        // encrypted column is decrypted once.
        expect(app(CredentialRepository::class)->hasResolved(
            (int) $tenant->getKey(),
            IntegrationProvider::Stripe,
            CredentialEnvironment::Live,
        ))->toBeTrue();
    });
})->group('fast');
