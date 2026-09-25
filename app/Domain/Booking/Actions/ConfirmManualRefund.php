<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\RefundMethod;
use App\Events\BookingRefunded;
use App\Jobs\ExecuteGatewayRefund;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * «Επιστράφηκε»: the operator has handed back money that came in as cash or by
 * transfer (2026-09-23).
 *
 * {@see RefundBooking} writes such a refund as a `pending` row and calls
 * nobody, because nobody can be called — the money goes back across a desk or
 * from the operator's own bank. This is the other half: the row becomes
 * `succeeded`, the booking's money is recomputed from its rows (PAY-10, the
 * same arithmetic {@see ExecuteGatewayRefund} does when a gateway
 * settles), and {@see BookingRefunded} puts it on the trail with who did it.
 *
 * Idempotent: a row already settled, or one that is not a manual refund,
 * changes nothing and returns false.
 */
final class ConfirmManualRefund
{
    /** @return bool whether the refund was recorded now */
    public function __invoke(Payment $refund): bool
    {
        $settled = DB::transaction(function () use ($refund): ?Booking {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($refund->getKey());

            if (! self::isOwed($locked)) {
                return null;
            }

            $locked->forceFill([
                'status' => PaymentStatus::Succeeded,
                'refunded_at' => now(),
            ])->save();

            /** @var Booking $booking */
            $booking = Booking::query()->lockForUpdate()->findOrFail($locked->booking_id);

            $paid = Payment::paidCentsFor($booking->getKey());

            $attributes = [
                'paid_cents' => $paid,
                'refunded_cents' => Payment::refundedCentsFor($booking->getKey()),
                'balance_cents' => max(0, $booking->total_cents - $paid),
            ];

            // Everything back: `refunded`, as when a gateway refund settles.
            if ($paid < 1 && $booking->status->canTransitionTo(BookingStatus::Refunded)) {
                $attributes['status'] = BookingStatus::Refunded;
            }

            $booking->forceFill($attributes)->save();

            return $booking;
        });

        if (! $settled instanceof Booking) {
            return false;
        }

        $refund->refresh();

        BookingRefunded::dispatch($settled, $refund->amount_cents, RefundMethod::Cash, null);

        return true;
    }

    /** A cash or transfer refund still waiting to be handed back. */
    public static function isOwed(Payment $payment): bool
    {
        return $payment->kind === PaymentKind::Refund
            && $payment->status === PaymentStatus::Pending
            && ! $payment->gateway->isExternal();
    }
}
