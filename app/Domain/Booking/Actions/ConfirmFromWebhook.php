<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Availability\Actions\ReleaseHold;
use App\Domain\Booking\Support\SeatCommitment;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Exceptions\CapacityExceeded;
use App\Exceptions\HoldRefused;
use App\Jobs\ExecuteGatewayRefund;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Vessel;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a verified webhook actually does (spec BKG-11, BKG-12, AVL-47).
 *
 * ## The webhook is the only authority for a successful payment
 *
 * BKG-11 says so outright, and everything earlier in the flow is a hypothesis
 * until it arrives: a `pending` payment row means a guest was *sent* somewhere,
 * a redirect means they arrived, and neither means anybody paid. Only this
 * moves a payment to `succeeded`.
 *
 * ## Success is idempotent by status, not by flag
 *
 * AVL-47: replaying a webhook for an already-confirmed booking is a no-op
 * returning 2xx. The check is the booking's own status rather than a
 * `processed` boolean, because the status is the thing that must not change
 * twice — a flag can be true while the transition ran halfway.
 *
 * ## Failure hands the seats back and tries to hold them again
 *
 * BKG-12, and the ordering matters. The seats are in `seats_sold` because
 * BKG-9 committed them at redirect, so they must come **out** first; then the
 * booking goes back to `draft` and a fresh hold is attempted. If the boat filled
 * while the guest was failing to pay, there is nothing to hold and the booking
 * expires with a reason a guest-facing page can explain.
 *
 * Attempting the hold *after* releasing is what makes the common case work: the
 * seats the guest just released are the ones they are about to re-take, and a
 * hold attempted before the release would compete with itself.
 */
final class ConfirmFromWebhook
{
    public function __construct(
        private readonly ConfirmBooking $confirmBooking,
        private readonly ComputeBalanceDueAt $computeBalanceDueAt,
        private readonly HoldSeats $holdSeats,
        private readonly CancelBooking $cancelBooking,
        private readonly RecomputeBookingMoney $recomputeMoney,
        private readonly RefundBooking $refundBooking,
    ) {}

    public function __invoke(Payment $payment, bool $succeeded): void
    {
        $booking = $payment->booking;

        if (! $booking instanceof Booking) {
            return;
        }

        if ($succeeded) {
            $this->succeed($payment, $booking);

            return;
        }

        $this->fail($payment, $booking);
    }

    /**
     * Money arrived. What it does depends on where the booking is **now**,
     * which is not always where it was when the guest was sent to pay
     * (2026-09-25):
     *
     * - **`confirmed`, `checked_in`, `completed`**: a balance, or a replay
     *   (AVL-47). The payment is recorded and the money recomputed (PAY-10);
     *   nothing transitions twice. Anything paid on top of the total goes back.
     * - **`expired`**: the checkout lapsed while the guest was paying. The
     *   booking is confirmed if its seats (or its boat) are still there, and
     *   refunded in full if not.
     * - **`cancelled`, `refunded`**: refunded in full.
     * - **anything that can still be confirmed** is confirmed.
     *
     * Every refund here is written against the charge it reverses under
     * {@see RefundBooking::LATE_KEY_PREFIX}, which is how «Χρειάζονται
     * προσοχή» tells the operator, and how a replayed webhook knows the money
     * already went back.
     */
    private function succeed(Payment $payment, Booking $booking): void
    {
        if ($payment->kind === PaymentKind::Refund) {
            return;
        }

        $booking->refresh();

        match ($booking->status) {
            BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed => $this->recordOnLiveBooking($payment, $booking),
            BookingStatus::Expired => $this->recordAfterExpiry($payment, $booking),
            BookingStatus::Cancelled, BookingStatus::Refunded => $this->recordOnEndedBooking($payment, $booking),
            default => $this->confirm($payment, $booking),
        };
    }

    private function confirm(Payment $payment, Booking $booking): void
    {
        $this->markPaid($payment);

        // `fromCheckout` only for `pending_payment`, whose seats moved into
        // `seats_sold` at redirect (BKG-9) and must not be taken again. A
        // draft — the one BKG-12 put back after a declined card, paid on a
        // second try at the same order — holds its seats, or has lost them, and
        // the ordinary path moves them or refuses (2026-09-25). Passing `true`
        // for it confirmed a booking with no seats sold.
        $fromCheckout = $booking->status === BookingStatus::PendingPayment;

        try {
            $confirmed = ($this->confirmBooking)($booking->refresh(), fromCheckout: $fromCheckout);
        } catch (CapacityExceeded) {
            // The draft's hold had run out and the seats went to somebody else.
            // Paid for and not there: expired, and the money goes back.
            $this->expireDraft($booking);
            $this->refundLate($payment, $booking, $payment->amount_cents);

            return;
        } catch (HoldRefused $refused) {
            if ($refused->reason !== 'vessel_unavailable') {
                throw $refused;
            }

            // A private charter paid for after its boat had gone to somebody
            // else (2026-09-25). The money is in; the boat is not. So the
            // booking is cancelled and refunded in full — the operator did not
            // choose this and neither did the guest, so no policy applies — and
            // `AttentionItems` puts it in front of the operator before the
            // guest's call does. The `CancelDeparture` shape, for one booking.
            //
            // The money first: PAY-10's figure from the rows, since the
            // confirmation that would have written it never happened, and a
            // refund is computed from what the booking says was paid.
            $paid = Payment::paidCentsFor($booking->getKey());

            $booking->refresh()->forceFill([
                'paid_cents' => $paid,
                'balance_cents' => max(0, $booking->total_cents - $paid),
            ])->save();

            ($this->cancelBooking)(
                booking: $booking->refresh(),
                reason: CancelReason::VesselBookedPrivately,
                by: CancelledBy::System,
                refundInFull: true,
            );

            return;
        }

        // PRC-27.2: computed and written at confirmation, never derived on read.
        $confirmed->forceFill([
            'balance_due_at' => ($this->computeBalanceDueAt)($confirmed),
        ])->save();
    }

    private function markPaid(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::Succeeded) {
            return;
        }

        $payment->forceFill(['status' => PaymentStatus::Succeeded, 'paid_at' => now()])->save();
    }

    /**
     * BKG-12: back to `draft` with a fresh hold, or `expired` with a reason.
     */
    private function fail(Payment $payment, Booking $booking): void
    {
        if ($booking->status !== BookingStatus::PendingPayment) {
            // Already confirmed by another payment, already expired by the
            // sweeper, or already back in draft. A failure webhook arriving
            // late must not undo any of those.
            return;
        }

        $payment->forceFill(['status' => PaymentStatus::Failed])->save();

        $departure = DB::transaction(function () use ($booking): ?Departure {
            // AVL-45's order, as everywhere: vessel, departure, booking.
            if ($booking->vessel_id !== null) {
                Vessel::query()->lockForUpdate()->find($booking->vessel_id);
            }

            $departure = $booking->departure_id === null
                ? null
                : Departure::query()->lockForUpdate()->find($booking->departure_id);

            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            if ($departure instanceof Departure) {
                // Out of `seats_sold` first. They went in at redirect and the
                // guest did not pay for them.
                SeatCommitment::release($departure, $locked->pax_capacity_total);
            }

            $locked->forceFill([
                'status' => BookingStatus::Draft,
                'hold_expires_at' => null,
            ])->save();

            return $departure;
        });

        if (! $departure instanceof Departure) {
            return;
        }

        try {
            // The common case: the seats just released are the ones being
            // re-held, which is why this runs after the release rather than
            // before — a hold attempted first would compete with itself.
            ($this->holdSeats)($booking->refresh(), $departure);
        } catch (Throwable) {
            // The boat filled while the guest was failing to pay. BKG-12's
            // other branch: expired, with a reason a guest-facing page can turn
            // into a sentence.
            $booking->refresh()->forceFill([
                'status' => BookingStatus::Expired,
                'cancel_reason' => CancelReason::PaymentFailed,
                'hold_expires_at' => null,
            ])->save();
        }
    }

    /*
    | The helpers below lock the booking alone, or all three in AVL-45's order,
    | and sit after `fail()` so `LockDisciplineTest` still reads that order
    | first: it checks where each lock first appears in the file.
    */

    /**
     * A balance on a booking that is going ahead (or has sailed), or a replay.
     *
     * The status stays where it is; the money columns and the due date are
     * recomputed from the rows under the booking's lock, so the guest is not
     * offered the balance again and the reminders stop. Money on top of the
     * total — an older tab paid after a newer one, cash taken while a card page
     * was open — goes back, but only the first time this charge is recorded: a
     * replay has nothing new to give back.
     */
    private function recordOnLiveBooking(Payment $payment, Booking $booking): void
    {
        $refund = DB::transaction(function () use ($payment, $booking): ?Payment {
            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            $firstTime = $payment->refresh()->status !== PaymentStatus::Succeeded;

            $this->markPaid($payment);
            ($this->recomputeMoney)($locked);

            if (! $firstTime) {
                return null;
            }

            $surplus = Payment::paidCentsFor($locked->getKey()) - $locked->total_cents;

            return $surplus > 0 ? $this->refundBooking->lateRefundRow($locked, $payment, $surplus) : null;
        });

        $this->send($refund);
    }

    /**
     * Paid after the checkout lapsed (the sweeper got there first, or a
     * declined card left nothing to hold again).
     *
     * Confirmed if it still can be — the sailing still on sale and in the
     * future, the seats still there (taken afresh, never assumed), a charter's
     * boat still free — and refunded in full if not. Not keyed on "first
     * time": a retry after a crash between the two steps must still finish the
     * job, and a charge already given back is what makes a replay a no-op.
     */
    private function recordAfterExpiry(Payment $payment, Booking $booking): void
    {
        if ($this->alreadyGivenBack($payment)) {
            return;
        }

        DB::transaction(function () use ($payment, $booking): void {
            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            $this->markPaid($payment);
            ($this->recomputeMoney)($locked);
        });

        if ($this->canStillSail($booking->refresh())) {
            try {
                ($this->confirmBooking)($booking, paidAfterExpiry: true);

                return;
            } catch (CapacityExceeded|HoldRefused) {
                // Gone. The refund below.
            }
        }

        $this->refundLate($payment, $booking, $payment->amount_cents);
    }

    /**
     * Paid for a booking that had already been cancelled: back in full.
     *
     * Only when this charge is newly recorded. A charge that was already
     * `succeeded` before the booking was cancelled was settled by that
     * cancellation's own refund, under the guest's policy, and a replay of its
     * webhook must not refund it a second time.
     */
    private function recordOnEndedBooking(Payment $payment, Booking $booking): void
    {
        $refund = DB::transaction(function () use ($payment, $booking): ?Payment {
            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            if ($payment->refresh()->status === PaymentStatus::Succeeded) {
                return null;
            }

            $this->markPaid($payment);
            ($this->recomputeMoney)($locked);

            return $this->refundBooking->lateRefundRow($locked, $payment, $payment->amount_cents);
        });

        $this->send($refund);
    }

    /** A draft whose seats went while it was being paid for: expired, hold released. */
    private function expireDraft(Booking $booking): void
    {
        DB::transaction(function () use ($booking): void {
            // AVL-45's order.
            if ($booking->vessel_id !== null) {
                Vessel::query()->lockForUpdate()->find($booking->vessel_id);
            }

            $departure = $booking->departure_id === null
                ? null
                : Departure::query()->lockForUpdate()->find($booking->departure_id);

            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            if ($locked->status !== BookingStatus::Draft) {
                return;
            }

            $locked->forceFill([
                'status' => BookingStatus::Expired,
                'cancel_reason' => CancelReason::PaymentFailed,
                'hold_expires_at' => null,
            ])->save();

            // The counter recounted from live holds, as `CancelBooking` does.
            if ($departure instanceof Departure) {
                $departure->forceFill(['seats_held' => ReleaseHold::liveHeldSeats($departure)])->save();
            }
        });
    }

    /** Write the late refund under the booking's lock, then queue it. */
    private function refundLate(Payment $payment, Booking $booking, int $cents): void
    {
        $refund = DB::transaction(function () use ($payment, $booking, $cents): ?Payment {
            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            $this->markPaid($payment->refresh());
            ($this->recomputeMoney)($locked);

            return $this->refundBooking->lateRefundRow($locked, $payment, $cents);
        });

        $this->send($refund);
    }

    /** After commit (AVL-46): the gateway call is a queued job. */
    private function send(?Payment $refund): void
    {
        if ($refund instanceof Payment && $refund->gateway->isExternal()) {
            ExecuteGatewayRefund::dispatch($refund->getKey(), null);
        }
    }

    private function alreadyGivenBack(Payment $payment): bool
    {
        return Payment::query()
            ->where('kind', PaymentKind::Refund->value)
            ->where('refunds_payment_id', $payment->getKey())
            ->where('idempotency_key', 'like', RefundBooking::LATE_KEY_PREFIX . '%')
            ->exists();
    }

    /** Is the trip this expired booking was for still one it could be on? */
    private function canStillSail(Booking $booking): bool
    {
        if ($booking->starts_at_utc->isPast()) {
            return false;
        }

        if ($booking->departure_id === null) {
            // A charter: `ConfirmBooking` asks whether the boat is still free.
            return true;
        }

        $departure = Departure::query()->find($booking->departure_id);

        return $departure instanceof Departure && $departure->status->isSellable();
    }
}
