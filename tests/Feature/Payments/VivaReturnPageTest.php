<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Viva's Success and Failure URLs — `/pay/viva/success`, `/pay/viva/failure`
|--------------------------------------------------------------------------
|
| Viva sends the guest back to two addresses set once on the merchant's payment
| source, with the order code appended as `s` and the transaction id as `t`.
| These pages find the booking by the order code and send the guest on.
|
| **The refusal to act is the test.** A return URL is a browser redirect anybody
| can type; confirmation is the webhook's alone (BKG-11). So every success case
| below also asserts that nothing was marked paid.
|
*/

const VIVA_ORDER_CODE = '4285163129072638';

/**
 * A live booking whose Viva payment is still waiting on the webhook.
 *
 * @return array{0: Tenant, 1: Booking, 2: Payment}
 */
function vivaReturnFixture(BookingStatus $status = BookingStatus::PendingPayment): array
{
    $tenant = Tenant::factory()->create();

    [$booking, $payment] = Tenancy::forTenant($tenant, static function () use ($status): array {
        $departure = Departure::factory()->create([
            'capacity' => 10,
            'seats_sold' => 2,
            'seats_held' => 0,
            'min_pax' => 0,
        ]);

        $booking = Booking::factory()
            ->forDeparture($departure)
            ->withPax(2, 2)
            ->pendingPayment()
            ->create([
                'status' => $status,
                'total_cents' => 12000,
                'paid_cents' => 0,
                'balance_cents' => 12000,
                'is_test' => false,
            ]);

        $payment = Payment::factory()->create([
            'booking_id' => $booking->getKey(),
            'amount_cents' => 12000,
            'gateway' => PaymentGatewayName::Viva,
            'gateway_ref' => VIVA_ORDER_CODE,
            'status' => PaymentStatus::Pending,
        ]);

        return [$booking->refresh(), $payment];
    });

    return [$tenant, $booking, $payment];
}

/** Nothing on the booking or the payment moved. */
function expectNothingPaid(Tenant $tenant, Booking $booking, BookingStatus $status): void
{
    Tenancy::forTenant($tenant, static function () use ($booking, $status): void {
        $fresh = Booking::query()->findOrFail($booking->getKey());
        $payment = Payment::query()->where('booking_id', $booking->getKey())->firstOrFail();

        expect($fresh->status)->toBe($status)
            ->and($fresh->paid_cents)->toBe(0)
            ->and($payment->status)->toBe(PaymentStatus::Pending);
    });
}

it('sends a guest back from a successful payment to their booking page', function (): void {
    [$tenant, $booking] = vivaReturnFixture();

    get('/pay/viva/success?t=b1f1c0de-0000-4000-8000-000000000001&s=' . VIVA_ORDER_CODE . '&lang=el-GR&eventId=0&eci=1')
        ->assertRedirect(route('guest.booking', ['token' => $booking->manage_token]));

    // Navigation only: the webhook confirms, and it has not arrived.
    expectNothingPaid($tenant, $booking, BookingStatus::PendingPayment);
})->group('fast');

it('sends a guest back from a failed payment to the checkout page to try again', function (): void {
    [$tenant, $booking] = vivaReturnFixture();

    get('/pay/viva/failure?t=b1f1c0de-0000-4000-8000-000000000002&s=' . VIVA_ORDER_CODE)
        ->assertRedirect(route('guest.checkout', ['token' => $booking->manage_token]))
        ->assertSessionHasErrors(['checkout' => __('guest.checkout.payment_failed', [], $booking->locale)]);

    expectNothingPaid($tenant, $booking, BookingStatus::PendingPayment);
})->group('fast');

it('shows the failure sentence on the checkout page it lands on', function (): void {
    [, $booking] = vivaReturnFixture();

    get('/pay/viva/failure?s=' . VIVA_ORDER_CODE)->assertRedirect();

    // The flashed error survives to the next request, which is the one the
    // browser makes after the redirect.
    get('/c/' . $booking->manage_token)
        ->assertOk()
        ->assertSee(__('guest.checkout.payment_failed', [], $booking->locale), escape: false);
})->group('fast');

it('sends a failure for a booking that can no longer be paid to its booking page', function (): void {
    // Already confirmed — by another payment, or by a webhook that beat the
    // guest's browser back. There is nothing to try again.
    [, $booking] = vivaReturnFixture(BookingStatus::Confirmed);

    get('/pay/viva/failure?s=' . VIVA_ORDER_CODE)
        ->assertRedirect(route('guest.booking', ['token' => $booking->manage_token]));
})->group('fast');

it('answers an order code it does not know with the link-not-valid page', function (): void {
    vivaReturnFixture();

    get('/pay/viva/success?s=9999999999999999')
        ->assertNotFound()
        ->assertSee(__('guest.link.title'), escape: false);

    get('/pay/viva/failure?s=9999999999999999')->assertNotFound();
})->group('fast');

it('answers a missing or malformed order code the same way', function (): void {
    vivaReturnFixture();

    get('/pay/viva/success')->assertNotFound();
    get('/pay/viva/failure?s[]=' . VIVA_ORDER_CODE)->assertNotFound();
    get('/pay/viva/success?s=' . urlencode("' or 1=1 --"))->assertNotFound();
})->group('fast');

it('does not resolve another gateway\'s reference', function (): void {
    [$tenant, $booking] = vivaReturnFixture();

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        Payment::query()->where('booking_id', $booking->getKey())->update(['gateway' => PaymentGatewayName::Cash->value]);
    });

    get('/pay/viva/success?s=' . VIVA_ORDER_CODE)->assertNotFound();
})->group('fast');
