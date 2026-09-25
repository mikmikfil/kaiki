<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Availability\Support\BookingCutoff;
use App\Domain\Availability\Support\SeatAdmission;
use App\Domain\Booking\Support\CharterOccupancy;
use App\Domain\Booking\Support\CheckoutDetails;
use App\Domain\Booking\Support\OpenGatewayOrders;
use App\Domain\Booking\Support\QuotePaymentDeadline;
use App\Domain\Booking\Support\SeatCommitment;
use App\Domain\Pricing\Actions\ApplyDiscountCode;
use App\Domain\Pricing\Actions\ApplyVoucher;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Exceptions\CapacityExceeded;
use App\Exceptions\CheckoutRefused;
use App\Exceptions\DiscountCodeRefused;
use App\Exceptions\HoldRefused;
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
        private readonly SeatAdmission $admission,
    ) {}

    /**
     * @return array{booking: Booking, payment: Payment|null}
     *
     * @throws CapacityExceeded when the seats went while the guest was deciding
     * @throws CheckoutRefused when nobody has said whose booking this is, or it is too late (AVL-19)
     * @throws HoldRefused when a charter's boat went to somebody else (2026-09-25)
     * @throws IllegalStateTransition when the booking is neither a live draft nor at the gateway
     */
    public function __invoke(Booking $booking, PaymentGatewayName $gateway = PaymentGatewayName::Viva): array
    {
        if (! self::canCheckOut($booking)) {
            throw IllegalStateTransition::forBooking($booking->status, BookingStatus::PendingPayment);
        }

        // AVL-19 at the line money crosses (2026-09-25). An old link, a resumed
        // checkout or a charter draft left by a declined card can reach this
        // long after the draft was made. A guest's own draft keeps the lead
        // time; a booking already at the gateway, or one the operator priced
        // (an accepted quote, BKG-32), is refused only once the trip starts.
        $leadTime = $booking->status === BookingStatus::Draft && $booking->source->isGuestInitiated();

        if (BookingCutoff::forBooking($booking, $leadTime) !== null) {
            throw CheckoutRefused::tooLate();
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

        // The passengers and the required questions, as the checkout page
        // asks them (audit 2): the API has no fields for either. A draft only;
        // a booking already at the gateway passed this on its way there.
        if ($booking->status === BookingStatus::Draft) {
            CheckoutDetails::assertComplete($booking);
        }

        /** @var array{booking: Booking, payment: Payment|null, zeroTotal: bool} $result */
        $result = DB::transaction(function () use ($booking, $gateway): array {
            // AVL-45's order: vessel, departure, booking. Unconditional, on
            // every driver — AVL-43.1 calls a skipped lock a review blocker.
            $vessel = $booking->vessel_id === null
                ? null
                : Vessel::query()->lockForUpdate()->find($booking->vessel_id);

            $departure = $booking->departure_id === null
                ? null
                : Departure::query()->lockForUpdate()->find($booking->departure_id);

            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            // The status again, under the lock (2026-09-25). A double tap on
            // «Πληρωμή», or the hold sweeper, may have moved it since the read
            // above; the answer that counts is this one.
            if (! self::canCheckOut($locked)) {
                throw IllegalStateTransition::forBooking($locked->status, BookingStatus::PendingPayment);
            }

            // Already at the gateway: an accepted quote with no card page yet,
            // a guest back from Viva, a total changed under an open order. The
            // seats were committed when it got here, so they are not taken
            // again; the old order, if any, is withdrawn, and a new one is
            // minted below at what is owed now.
            $again = $locked->status === BookingStatus::PendingPayment;

            // An accepted quote past its payment deadline (audit 2). Only the
            // sweeper used to keep it, and every new card page pushed the
            // sweeper back an hour: a guest could pay late, or hold the boat to
            // the trip by pressing «Πληρωμή» once an hour.
            $quoteDeadline = $again ? QuotePaymentDeadline::for($locked) : null;

            if ($quoteDeadline !== null && $quoteDeadline->isPast()) {
                throw CheckoutRefused::tooLate();
            }

            // A private charter, the line before money (2026-09-25): its hold
            // may have lapsed on the checkout page while somebody else took the
            // boat. Asked under the vessel lock, leaving this booking's own
            // hold out of the answer.
            if ($departure === null && $vessel instanceof Vessel && ! CharterOccupancy::isFreeFor($vessel, $locked)) {
                throw HoldRefused::vesselUnavailable();
            }

            // And the per-seat side of the same question (2026-09-25): a sailing
            // on a boat chartered or blocked since the hold was taken — or held
            // on a page opened before — does not reach the gateway.
            if ($departure instanceof Departure && $vessel instanceof Vessel && ! HoldSeats::vesselIsFreeFor($vessel, $departure)) {
                throw HoldRefused::departureUnavailable();
            }

            // Re-verified here as well as at confirmation, because the voucher
            // may have been spent on another booking since the draft was made
            // and the total the guest is about to be charged depends on it.
            ($this->applyVoucher)($locked);

            // And the discount code's last use, taken under a lock (2026-09-18).
            // The checkout page has already checked that the code is good; what
            // it could not do is stop somebody else spending the last use while
            // this guest typed their passport number. Whoever reaches this line
            // second finds the count already includes the first.
            if (! ApplyDiscountCode::claim($locked)) {
                throw new DiscountCodeRefused(__('discount_codes.refused.no_longer'));
            }

            if ($again) {
                OpenGatewayOrders::withdraw($locked);
            } elseif ($departure instanceof Departure) {
                // The move from held to sold (BKG-9). The guest's own hold is
                // passed in so their seats are not competed for twice.
                $ownHeld = $locked->holdsSeats() ? $locked->pax_capacity_total : 0;

                // A hold that lapsed on this page: its people dropped out of the
                // count and others may have boarded since, so the seats are
                // asked for as a new hold would ask (AVL-25, audit 2).
                if ($ownHeld === 0) {
                    $this->admission->refuseUnlessAdmissible($vessel, $departure, $locked);
                }

                if (! SeatCommitment::commit($departure, $locked->pax_capacity_total, $ownHeld)) {
                    throw CapacityExceeded::forDeparture($locked->pax_capacity_total);
                }
            }

            // What this page charges: the deposit where the rate plan asked for
            // one, the total otherwise — less what is already paid (audit 2).
            // Cash taken at the desk used to be charged again on the card and
            // then refunded to it.
            $takesDeposit = $locked->deposit_cents > 0 && $locked->deposit_cents < $locked->total_cents;
            $due = ($takesDeposit ? $locked->deposit_cents : $locked->total_cents)
                - Payment::paidCentsFor($locked->getKey());

            if ($locked->total_cents === 0 || $due < 1) {
                // BKG-19: nothing to pay. The seats are already committed above,
                // so confirmation must not commit them a second time. Also a
                // booking whose deposit, or all of it, is already paid: it is
                // confirmed, with whatever is left as its balance.
                return ['booking' => $locked, 'payment' => null, 'zeroTotal' => true];
            }

            $locked->forceFill([
                'status' => BookingStatus::PendingPayment,
                // The hold is over: these seats are sold now, not held.
                'hold_expires_at' => null,
                // Written even when the status is already this one: BKG-10's
                // sixty minutes run from the newest card page, not the first.
                'updated_at' => now(),
            ])->save();

            $payment = new Payment;

            $payment->forceFill([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $locked->getKey(),
                'gateway' => $gateway,
                // `deposit` where the rate plan asked for one, `full` otherwise.
                // The balance is a second session, months later if need be
                // (ADR-0004 Option D).
                'kind' => $takesDeposit ? PaymentKind::Deposit : PaymentKind::Full,
                'amount_cents' => $due,
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

    /**
     * A live draft, or a booking already at the gateway being sent there again.
     *
     * `pending_payment` is let back in on purpose (2026-09-25): an accepted
     * quote reaches it with no card page at all, and a guest who pressed Back
     * on Viva, or whose total changed, needs a new order rather than a refusal.
     */
    private static function canCheckOut(Booking $booking): bool
    {
        return $booking->status === BookingStatus::PendingPayment
            || $booking->status->canTransitionTo(BookingStatus::PendingPayment);
    }
}
