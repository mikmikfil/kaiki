<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Models\Booking;
use App\Models\Payment;

/**
 * PAY-10's write, in one place: a booking's money columns from its `Payment`
 * rows (2026-09-25).
 *
 * `paid_cents`, `balance_cents` and `refunded_cents` are recomputed from the
 * rows, never incremented; `balance_due_at` follows the balance (PRC-27.2), so a
 * booking with nothing left to pay loses its due date and the reminders stop.
 *
 * The status is not touched. That is the caller's business — a balance paid on a
 * `confirmed` or `checked_in` booking changes the money and nothing else.
 *
 * The caller holds the booking's row lock and passes the locked row; this takes
 * no lock of its own (`LockDisciplineTest` reads the Actions for their locks).
 * Written for {@see RecordManualPayment} and a balance arriving by webhook
 * ({@see ConfirmFromWebhook}), which until now did not recompute at all: the
 * guest was offered the balance again, and the reminders kept coming.
 */
final class RecomputeBookingMoney
{
    public function __construct(private readonly ComputeBalanceDueAt $computeBalanceDueAt) {}

    public function __invoke(Booking $locked): Booking
    {
        $paid = Payment::paidCentsFor($locked->getKey());

        $locked->forceFill([
            'paid_cents' => $paid,
            'balance_cents' => max(0, $locked->total_cents - $paid),
            'refunded_cents' => Payment::refundedCentsFor($locked->getKey()),
        ])->save();

        // After the balance, because the calculation reads it. A booking that
        // has ended owes nothing on a date, whatever its columns say.
        $due = $locked->status->isLive() ? ($this->computeBalanceDueAt)($locked) : null;

        // A due date already set does not move because some money arrived
        // (2026-09-25). Recomputed now, a part payment after the date took
        // PRC-27.3's «confirmed late» branch and pushed the date 24 hours on,
        // out of the overdue list. It is written fresh only when there was none
        // — a balance reopened on a booking that had been settled — and it goes
        // when nothing is owed or the operator collects on board.
        if ($due !== null && $locked->balance_due_at !== null) {
            $due = $locked->balance_due_at;
        }

        $locked->forceFill(['balance_due_at' => $due])->save();

        return $locked;
    }
}
