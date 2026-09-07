<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Events\ManualPaymentRecorded;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Money that arrived at a desk, on a boat or in a bank account (spec BKG-33,
 * OPS-5).
 *
 * {@see CreateManualBooking} already marks a booking paid **as it is created**,
 * which covers the walk-up. It does not cover the ordinary case: the phone call
 * on Tuesday and the cash on Saturday morning. Until now there was no way to
 * record that at all, so the dashboard's "owed to you" figure had no action
 * behind it and an operator's cash bookings stayed permanently unpaid in the
 * system.
 *
 * ## It is a payment, not an edit
 *
 * The tempting implementation is to set `paid_cents` on the booking. That is
 * wrong for the reason PAY-10 exists: `paid_cents` is *derived* from the
 * `Payment` rows and recomputed inside the transaction, never incremented. A
 * booking whose column says it is paid and which has no payment row behind it
 * is a booking the accountant cannot explain, and the nightly invariant check
 * would fail on it.
 *
 * It also means a payment recorded in error is corrected by recording a
 * correction — a refund — rather than by making the first one disappear. The
 * audit trail is the point (BKG-33, AUD-1).
 *
 * ## The kind is derived, and it matters
 *
 * `Full` only when this settles the whole total on a booking that had paid
 * nothing; `Balance` otherwise. Writing `Full` for a cash top-up on a booking
 * that already paid a deposit online would double the amount every later report
 * reads — which is exactly what a naive "mark as paid" does, and it does it
 * silently.
 *
 * ## It never takes more than is owed
 *
 * An operator typing 150 into a box against a €120 balance has made a mistake,
 * and accepting it would leave a booking that has overpaid with no way to say
 * so. Refused with the balance named, in their own language.
 */
final class RecordManualPayment
{
    public function __construct(
        private readonly ConfirmBooking $confirmBooking,
        private readonly ComputeBalanceDueAt $computeBalanceDueAt,
    ) {}

    /**
     * @param  int  $amountCents  What actually arrived.
     * @param  PaymentGatewayName  $gateway  `Cash` or `BankTransfer`; nothing else records money that is already here.
     * @param  string|null  $reference  A bank reference or a receipt number, for the operator's own reconciliation.
     *
     * @throws ValidationException
     */
    public function __invoke(
        Booking $booking,
        int $amountCents,
        PaymentGatewayName $gateway,
        ?string $reference = null,
        ?int $userId = null,
    ): Booking {
        $this->guard($booking, $amountCents, $gateway);

        $payment = DB::transaction(function () use ($booking, $amountCents, $gateway, $reference): Payment {
            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            // Re-read inside the lock. Between the guard and here, a webhook may
            // have settled the online payment this cash was going to cover.
            $owed = max(0, $locked->total_cents - Payment::paidCentsFor($locked->getKey()));

            if ($amountCents > $owed) {
                throw ValidationException::withMessages([
                    'amount' => [trans('bookings.payment.too_much', ['balance' => $owed / 100])],
                ]);
            }

            $payment = new Payment;

            $payment->forceFill([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $locked->getKey(),
                'gateway' => $gateway,
                'kind' => $this->kindFor($locked, $amountCents),
                'amount_cents' => $amountCents,
                'status' => PaymentStatus::Succeeded,
                // PAY-9: minted even though it deduplicates nothing here. A
                // payments table where the column is sometimes null is a table
                // every later query has to special-case.
                'idempotency_key' => (string) Str::uuid(),
                'gateway_ref' => $reference,
                'paid_at' => now(),
            ])->save();

            // PAY-10, in the same transaction as the row it is derived from and
            // recomputed rather than incremented. A booking already confirmed
            // stops here: this is the whole of what changed for it.
            $paid = Payment::paidCentsFor($locked->getKey());

            $locked->forceFill([
                'paid_cents' => $paid,
                'balance_cents' => max(0, $locked->total_cents - $paid),
                'refunded_cents' => Payment::refundedCentsFor($locked->getKey()),
            ])->save();

            // PRC-27.2, written after the balance because it reads it. A
            // booking settled in cash must lose its due date here, or the
            // reminder scheduler goes on chasing a guest who has already paid —
            // which is the complaint that arrives from the guest, not from the
            // operator.
            $locked->forceFill([
                'balance_due_at' => ($this->computeBalanceDueAt)($locked),
            ])->save();

            return $payment;
        });

        $booking = $this->settle($booking->refresh());

        // The trail is event-driven (ADR-0025): the listener captures the
        // ambient user and tenant, which a queued write cannot see. Dispatched
        // **after** settling, so the row carries the arithmetic as it now
        // stands rather than as it stood a moment ago.
        ManualPaymentRecorded::dispatch($booking, $amountCents, $gateway, $payment->uuid, $reference);

        return $booking;
    }

    /**
     * What the payment settles.
     *
     * @throws ValidationException
     */
    private function guard(Booking $booking, int $amountCents, PaymentGatewayName $gateway): void
    {
        if ($amountCents <= 0) {
            throw ValidationException::withMessages([
                'amount' => [trans('bookings.payment.not_positive')],
            ]);
        }

        if (! in_array($gateway, [PaymentGatewayName::Cash, PaymentGatewayName::BankTransfer], true)) {
            // A gateway payment has a gateway behind it and arrives by webhook.
            // Recording one by hand would put money in the books that nobody
            // can reconcile against a settlement file.
            throw ValidationException::withMessages([
                'gateway' => [trans('bookings.payment.wrong_gateway')],
            ]);
        }

        if (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::Refunded, BookingStatus::Expired], true)) {
            throw ValidationException::withMessages([
                'amount' => [trans('bookings.payment.not_live')],
            ]);
        }
    }

    /**
     * `Full` when this is the whole of it and nothing was paid before;
     * `Balance` in every other case.
     *
     * `Deposit` is deliberately never written here. A deposit is a *rule* about
     * how much is due now (PRC-27), decided by the product and written at
     * checkout — not a name for "less than the total happened to arrive".
     */
    private function kindFor(Booking $booking, int $amountCents): PaymentKind
    {
        $alreadyPaid = Payment::paidCentsFor($booking->getKey());

        return $alreadyPaid === 0 && $amountCents === $booking->total_cents
            ? PaymentKind::Full
            : PaymentKind::Balance;
    }

    /**
     * Confirm the booking, when paying it is what confirms it.
     *
     * A `draft` or `pending_payment` booking that is now fully paid goes
     * through {@see ConfirmBooking}, because that is what taking the money for
     * one means — and it is the action that commits the seats, freezes the
     * policy and fires BKG-13's notifications. Nothing here reimplements any of
     * that.
     *
     * **Everything else is left alone**, and the guard is the point rather than
     * an optimisation: `confirmed` cannot transition to `confirmed`, so pushing
     * an already-confirmed booking through would throw
     * `IllegalStateTransition` — on the most ordinary case there is, an
     * operator collecting the balance in cash on the morning of the trip.
     *
     * A part payment also stops here. A booking half paid in cash is still
     * `pending_payment`, which is exactly what it is.
     */
    private function settle(Booking $booking): Booking
    {
        if ($booking->balance_cents > 0) {
            return $booking;
        }

        if (! $booking->status->canTransitionTo(BookingStatus::Confirmed)) {
            return $booking;
        }

        return ($this->confirmBooking)($booking);
    }
}
