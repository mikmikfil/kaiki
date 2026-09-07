<?php

declare(strict_types=1);

use App\Domain\Payments\Gateways\FakeGateway;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
|--------------------------------------------------------------------------
| The sandbox checkout page — SAA-9, PAY-11, issue 111
|--------------------------------------------------------------------------
|
| The fake gateway used to redirect to a host that could not resolve, which was
| right while nothing was ever going to follow it. Two things now do: SAA-9's
| onboarding ends with "a test booking in sandbox mode", and the end-to-end run
| has to reach a payment and come back without a third-party sandbox.
|
| **The refusals are the test.** The page confirms bookings, and its URL is a
| gateway reference — guessable in principle and stored on a row anybody with
| database access can read. Everything below asks what it does when it should
| not act, and only then what it does when it should.
|
| The one that matters most is `is_test`. PAY-11: sandbox mode must be
| impossible to enable accidentally on a live tenant, and `bookings.is_test` is
| written at creation from the key that created it and never changes — so a
| refusal keyed on it cannot be talked into anything by a request.
|
*/

/**
 * A tenant with a test booking sitting at the gateway.
 *
 * @return array{0: Tenant, 1: Booking, 2: Payment}
 */
function sandboxFixture(bool $isTest = true, PaymentStatus $status = PaymentStatus::Pending, ?string $returnUrl = null): array
{
    $tenant = Tenant::factory()->create();

    [$booking, $payment] = Tenancy::forTenant($tenant, static function () use ($isTest, $status, $returnUrl): array {
        $departure = Departure::factory()->create([
            'capacity' => 10,
            // BKG-9 committed them at redirect, which is the state a settlement
            // actually arrives into.
            'seats_sold' => 2,
            'seats_held' => 0,
            'min_pax' => 0,
        ]);

        $booking = Booking::factory()
            ->forDeparture($departure)
            ->withPax(2, 2)
            ->pendingPayment()
            ->create([
                'total_cents' => 12000,
                'paid_cents' => 0,
                'balance_cents' => 12000,
                'is_test' => $isTest,
            ]);

        $payment = Payment::factory()->create([
            'booking_id' => $booking->getKey(),
            'amount_cents' => 12000,
            'gateway' => PaymentGatewayName::Viva,
            'gateway_ref' => 'fake_sandbox_reference',
            'status' => $status,
            'return_url' => $returnUrl,
        ]);

        return [$booking->refresh(), $payment];
    });

    return [$tenant, $booking, $payment];
}

it('refuses a live booking, whatever else is true of it', function (): void {
    // The load-bearing refusal. A page that could be talked into confirming a
    // live booking is a page that confirms bookings nobody paid for.
    sandboxFixture(isTest: false);

    get('/sandbox/checkout/fake_sandbox_reference')->assertNotFound();
    post('/sandbox/checkout/fake_sandbox_reference/pay')->assertNotFound();
})->group('fast');

it('refuses a reference that names no payment', function (): void {
    sandboxFixture();

    get('/sandbox/checkout/fake_not_a_reference')->assertNotFound();
})->group('fast');

it('refuses a payment that has already been settled', function (): void {
    // Re-confirming is harmless today only because `ConfirmFromWebhook` is
    // idempotent, and depending on somebody else's idempotency for your own
    // safety is how it stops being true.
    sandboxFixture(status: PaymentStatus::Succeeded);

    get('/sandbox/checkout/fake_sandbox_reference')->assertNotFound();
    post('/sandbox/checkout/fake_sandbox_reference/pay')->assertNotFound();
})->group('fast');

it('says it is a test before it says anything else', function (): void {
    sandboxFixture();

    $response = get('/sandbox/checkout/fake_sandbox_reference')->assertSuccessful();

    $html = (string) $response->getContent();

    expect($html)->toContain(__('sandbox.banner'))
        // Never indexed: it is a payment page for a booking that is not real.
        ->and($html)->toContain('noindex')
        // No card field anywhere. A form that asked for a number would teach
        // somebody to type a real one.
        ->and($html)->not->toContain('type="password"')
        ->and($html)->not->toContain('autocomplete="cc-number"');

    // The banner comes before the amount in the document, not after it.
    expect(strpos($html, __('sandbox.banner')))->toBeLessThan((int) strpos($html, __('sandbox.amount')));
})->group('fast');

it('confirms the booking through the same action a webhook uses', function (): void {
    [$tenant, $booking] = sandboxFixture();

    post('/sandbox/checkout/fake_sandbox_reference/pay')->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $fresh = Booking::query()->findOrFail($booking->getKey());
        $payment = Payment::query()->where('booking_id', $booking->getKey())->firstOrFail();

        expect($fresh->status)->toBe(BookingStatus::Confirmed)
            ->and($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($fresh->paid_cents)->toBe(12000);
    });
})->group('fast');

it('hands the seats back when the card is declined', function (): void {
    // BKG-12, and the half of the flow a sandbox that only succeeded would
    // never exercise.
    [$tenant, $booking] = sandboxFixture();

    post('/sandbox/checkout/fake_sandbox_reference/fail')->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $fresh = Booking::query()->findOrFail($booking->getKey());
        $payment = Payment::query()->where('booking_id', $booking->getKey())->firstOrFail();

        expect($fresh->status)->not->toBe(BookingStatus::Confirmed)
            ->and($payment->status)->toBe(PaymentStatus::Failed);
    });
})->group('fast');

it('returns the guest to the address the checkout request named', function (): void {
    sandboxFixture(returnUrl: 'https://aegean-blue.example/thank-you');

    post('/sandbox/checkout/fake_sandbox_reference/pay')
        ->assertRedirect('https://aegean-blue.example/thank-you');
})->group('fast');

it('falls back to the guest booking page when nothing named a return address', function (): void {
    // An operator running SAA-9's test booking from the panel has no host page
    // to go back to, and a redirect to nowhere would end onboarding on a blank
    // screen.
    [, $booking] = sandboxFixture(returnUrl: null);

    post('/sandbox/checkout/fake_sandbox_reference/pay')
        ->assertRedirect(route('guest.booking', ['token' => $booking->manage_token]));
})->group('fast');

it('is where the fake gateway actually sends a guest', function (): void {
    // The seam between the two halves of this: if the gateway stopped pointing
    // here, every test above would still pass and nobody would ever reach the
    // page.
    expect(FakeGateway::checkoutUrl('fake_sandbox_reference'))
        ->toBe(route('sandbox.checkout', ['reference' => 'fake_sandbox_reference']));
})->group('fast');
