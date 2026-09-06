<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Payments\Data\RedirectTarget;
use App\Domain\Payments\Support\GatewayResolver;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The second checkout session (spec PAY-4.3, ADR-0004 Option D, TOK-6).
 *
 * ## Minted on demand, and priced at the moment the guest opens the page
 *
 * ADR-0004 item 3, and the reason is the sentence that follows it: *"so a
 * legitimately changed balance is charged correctly."* Between the confirmation
 * email and the guest clicking the link, weeks can pass and the balance can
 * legitimately move — extras added, pax changed, an operator discount applied.
 * A session created at confirmation time would charge the old figure, and the
 * guest would owe money nobody asked them for.
 *
 * So the price comes from `booking.balance_cents` **now**, in this call.
 *
 * ## Emailed links point at `/b/{manage_token}`, never at a gateway URL
 *
 * PAY-4 item 3 is explicit. A gateway checkout URL expires — Stripe's in 24
 * hours, Viva's on the order timeout — so a link emailed today is dead by the
 * time a guest opens it in three weeks, and they have no way back. The manage
 * page is ours, lives as long as the booking, and mints the session when they
 * arrive.
 *
 * ## A second `Payment` row, not a mutation of the first
 *
 * PAY-8's `kind` is `balance`. The deposit row stays exactly as it was — it
 * records money that actually moved, and §2.5 says a payment is never mutated
 * except by its own status. Two gateway fees instead of one is ADR-0004's
 * accepted cost.
 */
final class MintBalanceSession
{
    public function __construct(private readonly GatewayResolver $gateways) {}

    /**
     * @return RedirectTarget|null null when there is nothing to pay, or no
     *                             gateway to pay it through
     */
    public function __invoke(Booking $booking): ?RedirectTarget
    {
        if (! $this->owesABalance($booking)) {
            return null;
        }

        $gateway = $this->gateways->forBooking($booking);

        if ($gateway === null) {
            return null;
        }

        // **Priced now.** See the class docblock: this is the whole point of
        // minting on demand rather than at confirmation.
        $amount = Money::ofMinor($booking->balance_cents, 'EUR');

        $payment = DB::transaction(function () use ($booking): Payment {
            // An open balance payment from an earlier visit is reused rather
            // than duplicated — a guest who opened the page, thought better of
            // it and came back an hour later should not leave two pending rows
            // in the operator's stuck-payment feed.
            //
            // Its amount is refreshed, because the balance may have moved
            // between the two visits, which is the same reason the session is
            // minted late at all.
            $existing = Payment::query()
                ->where('booking_id', $booking->getKey())
                ->where('kind', PaymentKind::Balance->value)
                ->open()
                ->latest('id')
                ->first();

            if ($existing instanceof Payment) {
                $existing->forceFill(['amount_cents' => $booking->balance_cents])->save();

                return $existing;
            }

            $payment = new Payment;

            $payment->forceFill([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $booking->getKey(),
                'gateway' => $this->gatewayNameFor($booking),
                'kind' => PaymentKind::Balance,
                'amount_cents' => $booking->balance_cents,
                'status' => PaymentStatus::Pending,
                // PAY-9, again: minted before the call, never derived from a
                // response we may never receive.
                'idempotency_key' => (string) Str::uuid(),
            ])->save();

            return $payment;
        });

        $target = $gateway->createCheckoutSession($booking, PaymentKind::Balance, $amount);

        $payment->forceFill([
            'gateway_ref' => $target->reference,
            // Un-indexed and short-lived (§2.5). It is what this redirect is
            // built from, not what a link is built from later.
            'checkout_url' => $target->url,
        ])->save();

        return $target;
    }

    /**
     * Is there a balance, on a booking that can still take one?
     *
     * A cancelled or expired booking may carry a non-zero `balance_cents` from
     * before it ended, and a guest who still has the link must not be able to
     * pay a balance on a trip that is not happening.
     */
    private function owesABalance(Booking $booking): bool
    {
        return $booking->balance_cents > 0
            && in_array($booking->status, [
                BookingStatus::Confirmed,
                BookingStatus::CheckedIn,
            ], strict: true);
    }

    private function gatewayNameFor(Booking $booking): PaymentGatewayName
    {
        // Whatever took the deposit takes the balance, where there was one: an
        // operator reconciling two rows against two different providers for one
        // booking is a support call nobody needs.
        $deposit = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', PaymentStatus::Succeeded->value)
            ->latest('id')
            ->first();

        // Viva when there is no earlier payment to follow — which happens for
        // a booking marked paid in cash and later given a balance, where there
        // is no gateway precedent to inherit.
        return $deposit === null ? PaymentGatewayName::Viva : $deposit->gateway;
    }
}
