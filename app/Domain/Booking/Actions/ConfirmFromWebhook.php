<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Booking\Support\SeatCommitment;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\PaymentStatus;
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

    private function succeed(Payment $payment, Booking $booking): void
    {
        if ($booking->status === BookingStatus::Confirmed) {
            // AVL-47. The payment is still marked succeeded — a replay may be
            // the *first* delivery of a webhook whose predecessor confirmed the
            // booking through another route — but nothing transitions twice.
            $this->markPaid($payment);

            return;
        }

        $this->markPaid($payment);

        // `fromCheckout: true` — the seats moved into `seats_sold` at redirect
        // (BKG-9), so confirmation must not take them again.
        $confirmed = ($this->confirmBooking)($booking->refresh(), fromCheckout: true);

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
}
