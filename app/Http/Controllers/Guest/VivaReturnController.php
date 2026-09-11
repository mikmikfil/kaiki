<?php

declare(strict_types=1);

namespace App\Http\Controllers\Guest;

use App\Domain\Booking\Actions\ConfirmFromWebhook;
use App\Domain\Payments\Gateways\VivaSmartCheckoutGateway;
use App\Enums\PaymentGatewayName;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/pay/viva/success` and `/pay/viva/failure` — where Viva sends a guest back.
 *
 * ## Why these exist
 *
 * Viva Smart Checkout does not take a return address per order: the Success
 * URL and Failure URL are set once, on the merchant's **payment source**, and
 * Viva appends the order to them as query parameters — `s` is the order code
 * (the `orderCode` {@see VivaSmartCheckoutGateway} stores as `gateway_ref`),
 * `t` the transaction id. Until 2026-09-11 there was nothing here for those
 * addresses to point at, so a guest who paid landed wherever the operator had
 * typed into Viva's dashboard, which was usually nowhere. The Integrations
 * page shows the operator both addresses to paste.
 *
 * ## Navigation only — never a confirmation
 *
 * BKG-11: the webhook is the only authority for a successful payment, and
 * {@see ConfirmFromWebhook} is the only code that moves seats or money. A
 * return URL is a browser redirect anybody can type, `s` and all, so nothing
 * here reads a status from the query string or writes one. It finds the
 * booking and sends the guest to the page that already tells the truth about
 * it: `/b/` after success (which says "pending" until the webhook lands), and
 * `/c/` to try again after a failure while the booking can still be paid for.
 *
 * ## The order code is looked up across tenants
 *
 * A guest arriving from Viva carries no host, key or token that names an
 * operator; the payment is what resolves the tenant, exactly as it is for the
 * webhook ({@see Payment::findByGatewayRef()}, `payments_gateway_ref_idx`).
 *
 * ## An unknown order code is TOK-4's page
 *
 * The routes sit in the token group, so a miss is the same branded "link not
 * valid" 404 as a bad token, counted against the same failure budget — an
 * order code is a sixteen-digit number, and a page that answered differently
 * for a real one would be a way to enumerate them.
 */
final class VivaReturnController extends GuestPageController
{
    public function success(Request $request): Response
    {
        $booking = $this->bookingFor($request);

        if ($booking === null) {
            return $this->linkNotValid($request);
        }

        return redirect()->route('guest.booking', ['token' => $booking->manage_token]);
    }

    public function failure(Request $request): Response
    {
        $booking = $this->bookingFor($request);

        if ($booking === null) {
            return $this->linkNotValid($request);
        }

        return CheckoutController::returnAfterFailedPayment($booking);
    }

    /** The booking behind Viva's `s`, or null for anything that does not name one. */
    private function bookingFor(Request $request): ?Booking
    {
        $orderCode = $request->query('s');

        // Shape-checked before it reaches a query: Viva's order codes are
        // digits, and anything that is not a short token-like string is not
        // one of ours.
        if (! is_string($orderCode) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderCode) !== 1) {
            return null;
        }

        $payment = Payment::findByGatewayRef(PaymentGatewayName::Viva, $orderCode);

        if (! $payment instanceof Payment) {
            return null;
        }

        return Tenancy::withoutTenancy(static fn (): ?Booking => Booking::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->find($payment->booking_id));
    }
}
