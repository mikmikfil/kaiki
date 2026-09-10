<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Support\SeatCommitment;
use App\Domain\Pricing\Actions\ApplyVoucher;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Exceptions\CapacityExceeded;
use App\Exceptions\CheckoutRefused;
use App\Exceptions\IllegalStateTransition;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Vessel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `POST /bookings/{uuid}/checkout`, minus the HTTP (spec BKG-9, BKG-19, PAY-9).
 *
 * ## Seats move at redirect, not at the webhook
 *
 * BKG-9, marked **RESOLVED**, and the reasoning is attached to the requirement:
 * *"committing at redirect rather than at webhook prevents the gap where a
 * guest is on the gateway page while another guest takes the last seat."*
 *
 * So this is where `seats_held` becomes `seats_sold`. The alternative reading —
 * commit when the money lands — leaves the seat available for the two or three
 * minutes a guest spends typing a card number, which is precisely the window in
 * which a popular Saturday sailing sells out.
 *
 * The cost is that an abandoned checkout holds a committed seat until BKG-10's
 * sweeper expires it. That is the accepted trade, and it is why that sweeper
 * exists rather than being optional.
 *
 * ## A zero total never reaches a gateway
 *
 * BKG-19 and PRC-22. A booking a voucher covers entirely goes straight to
 * {@see ConfirmBooking}: no `Payment` row, no session, no redirect. Sending a
 * guest to a payment page for €0.00 produces a checkout that cannot complete
 * and a gateway that rejects the request.
 *
 * ## The idempotency key is minted here, before anything is called
 *
 * PAY-9. A key derived from a gateway response cannot dedupe the request that
 * produced it — and the dangerous retry is the one where no response ever
 * arrived. The unique index on (`tenant_id`, `idempotency_key`) is what makes a
 * replayed job a no-op instead of a second charge.
 *
 * ## No gateway call happens here
 *
 * AVL-46, and also scope: the `PaymentGateway` contract and its two
 * implementations are #82. This Action creates the `pending` row and hands back
 * the booking; the caller mints the session **after** the transaction commits.
 */
final class StartCheckout
{
    public function __construct(
        private readonly ApplyVoucher $applyVoucher,
        private readonly ConfirmBooking $confirmBooking,
    ) {}

    /**
     * @return array{booking: Booking, payment: Payment|null}
     *
     * @throws CapacityExceeded when the seats went while the guest was deciding
     * @throws CheckoutRefused when nobody has said whose booking this is
     * @throws IllegalStateTransition when the booking is not a live draft
     */
    public function __invoke(Booking $booking, PaymentGatewayName $gateway = PaymentGatewayName::Viva): array
    {
        if (! $booking->status->canTransitionTo(BookingStatus::PendingPayment)) {
            throw IllegalStateTransition::forBooking($booking->status, BookingStatus::PendingPayment);
        }

        // ADR-0030's invariant, and it lives here rather than at draft creation
        // on purpose. A draft is a hold on seats; this is the line money
        // crosses, and it is the one line the API and the hosted checkout page
        // both pass through. Refusing here means a draft can be taken from a
        // widget that asks only for a date and a party, while nothing can
        // reach a gateway — or an invoice, or a manifest — anonymous.
        //
        // Consent is checked with the identity because they were recorded
        // together and both are evidence (GDR-9): a payment authorised by
        // somebody who never saw the terms is the dispute this prevents.
        if ($booking->guest_name === null || $booking->guest_email === null || $booking->terms_accepted_at === null) {
            throw CheckoutRefused::leadGuestRequired();
        }

        /** @var array{booking: Booking, payment: Payment|null, zeroTotal: bool} $result */
        $result = DB::transaction(function () use ($booking, $gateway): array {
            // AVL-45's order: vessel, departure, booking. Unconditional, on
            // every driver — AVL-43.1 calls a skipped lock a review blocker.
            if ($booking->vessel_id !== null) {
                Vessel::query()->lockForUpdate()->find($booking->vessel_id);
            }

            $departure = $booking->departure_id === null
                ? null
                : Departure::query()->lockForUpdate()->find($booking->departure_id);

            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            // Re-verified here as well as at confirmation, because the voucher
            // may have been spent on another booking since the draft was made
            // and the total the guest is about to be charged depends on it.
            ($this->applyVoucher)($locked);

            if ($departure instanceof Departure) {
                // The move from held to sold (BKG-9). The guest's own hold is
                // passed in so their seats are not competed for twice.
                $ownHeld = $locked->holdsSeats() ? $locked->pax_capacity_total : 0;

                if (! SeatCommitment::commit($departure, $locked->pax_capacity_total, $ownHeld)) {
                    throw CapacityExceeded::forDeparture($locked->pax_capacity_total);
                }
            }

            if ($locked->total_cents === 0) {
                // BKG-19: nothing to pay. The seats are already committed above,
                // so confirmation must not commit them a second time.
                return ['booking' => $locked, 'payment' => null, 'zeroTotal' => true];
            }

            $locked->forceFill([
                'status' => BookingStatus::PendingPayment,
                // The hold is over: these seats are sold now, not held.
                'hold_expires_at' => null,
            ])->save();

            $payment = new Payment;

            $payment->forceFill([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $locked->getKey(),
                'gateway' => $gateway,
                // `deposit` where the rate plan asked for one, `full` otherwise.
                // The balance is a second session, months later if need be
                // (ADR-0004 Option D).
                'kind' => $locked->deposit_cents > 0 && $locked->deposit_cents < $locked->total_cents
                    ? PaymentKind::Deposit
                    : PaymentKind::Full,
                'amount_cents' => $locked->deposit_cents > 0 && $locked->deposit_cents < $locked->total_cents
                    ? $locked->deposit_cents
                    : $locked->total_cents,
                'status' => PaymentStatus::Pending,
                // PAY-9: minted before the call, not after.
                'idempotency_key' => (string) Str::uuid(),
            ])->save();

            return ['booking' => $locked, 'payment' => $payment, 'zeroTotal' => false];
        });

        if ($result['zeroTotal']) {
            // Outside the transaction, and `fromCheckout: true` because the
            // seats were committed above — confirming would otherwise take them
            // twice and refuse the guest their own booking.
            return [
                'booking' => ($this->confirmBooking)($result['booking'], fromCheckout: true),
                'payment' => null,
            ];
        }

        return ['booking' => $result['booking'], 'payment' => $result['payment']];
    }
}
