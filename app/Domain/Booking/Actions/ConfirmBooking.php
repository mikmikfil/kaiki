<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Support\LockOrder;
use App\Domain\Booking\Support\SeatCommitment;
use App\Domain\Pricing\Actions\ApplyVoucher;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Events\BookingConfirmed;
use App\Events\DepartureGuaranteed;
use App\Exceptions\CapacityExceeded;
use App\Exceptions\IllegalStateTransition;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Vessel;
use Illuminate\Support\Facades\DB;

/**
 * The transaction the whole engine exists to get right (spec AVL-42 … AVL-49,
 * BKG-9, BKG-19, PRC-20, PRC-26, ADR-0006 Option A).
 *
 * `CLAUDE.md`: *"a booking that oversold is a guest on a quay with a ticket and
 * no seat."* Everything below is arranged around that one outcome.
 *
 * ## Two mechanisms, and neither is sufficient alone
 *
 * 1. **`lockForUpdate()`, unconditionally** (AVL-43.1). Vessel row, then
 *    departure row, then booking row, then voucher — {@see LockOrder}. It is
 *    **never** skipped or branched "for SQLite compatibility"; the spec calls
 *    that a review blocker, and `LockDisciplineTest` asserts it, because a
 *    conditional lock is invisible in a passing suite.
 *
 * 2. **A conditional counter update** (AVL-43.2), in {@see SeatCommitment}.
 *    The lock is a no-op on SQLite, so this is the portable guard: the seats
 *    are written with a `WHERE` clause that re-checks capacity at write time,
 *    and zero affected rows aborts with `CAPACITY_EXCEEDED`. It runs on every
 *    driver, so the invariant is exercised locally even though the race is not.
 *
 * ## Nothing external happens inside the lock
 *
 * AVL-46. No gateway call, no myDATA, no mail — {@see BookingConfirmed} is
 * dispatched **after commit**, and every listener is queued (BKG-13, BKG-14).
 * A row lock held across an HTTP call to a gateway is a row lock held for as
 * long as somebody else's server feels like taking, and on a busy Saturday that
 * is the whole boat.
 *
 * ## `guaranteed` is decided here and never reversed
 *
 * AVL-48 evaluates inside this transaction, because the seat count it reads is
 * only true inside it. AVL-49 is the other half, marked RESOLVED: a later
 * cancellation dropping the count below `min_pax` does **not** take it back —
 * *"reversing it would retract a promise already made to guests by email."*
 *
 * ## A zero total skips the gateway entirely
 *
 * BKG-19 and PRC-22: a booking fully covered by a voucher goes straight from
 * `draft` to `confirmed`, still emitting `BookingConfirmed` and all of BKG-13.
 * Sending a guest to a payment page for €0.00 is a checkout that cannot
 * complete.
 */
final class ConfirmBooking
{
    public function __construct(private readonly ApplyVoucher $applyVoucher) {}

    /**
     * @param  bool  $fromCheckout  true when the seats are already committed by
     *                              {@see StartCheckout} and must not be counted twice
     *
     * @throws CapacityExceeded when the seats went while the guest was paying
     * @throws IllegalStateTransition when the booking is not in a confirmable state
     */
    public function __invoke(Booking $booking, bool $fromCheckout = false): Booking
    {
        if (! $booking->status->canTransitionTo(BookingStatus::Confirmed)) {
            throw IllegalStateTransition::forBooking($booking->status, BookingStatus::Confirmed);
        }

        /** @var array{booking: Booking, guaranteed: bool} $result */
        $result = DB::transaction(function () use ($booking, $fromCheckout): array {
            // **AVL-45's order, inline and top to bottom.** Deliberately not
            // extracted into helpers: the order is the requirement, a reader
            // has to be able to see it without following two private methods,
            // and `LockDisciplineTest` reads the file rather than the call
            // graph — a static check cannot follow a helper, and one that
            // pretended to would be a check that passed on a reordering.
            //
            // The vessel first even though nothing on it changes here: it is
            // the coarsest of the four, and taking it last would let a
            // per-vessel charter and a per-seat confirmation acquire the same
            // two rows in opposite orders, which is the deadlock.
            if ($booking->vessel_id !== null) {
                Vessel::query()->lockForUpdate()->find($booking->vessel_id);
            }

            $departure = $booking->departure_id === null
                ? null
                : Departure::query()->lockForUpdate()->find($booking->departure_id);

            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            // PRC-20: re-validated **inside** the transaction with its own row
            // lock, so two concurrent bookings cannot spend the same voucher.
            // Last in the order because it is the only optional one.
            ($this->applyVoucher)($locked);

            $guaranteed = false;

            if ($departure instanceof Departure) {
                $guaranteed = $this->commitSeats($locked, $departure, $fromCheckout);
            }

            // PAY-10: recomputed from `Payment` rows inside this transaction,
            // never incremented. PRC-26's invariant is written here and asserted
            // by tests and the nightly check.
            $paid = Payment::paidCentsFor($locked->getKey());

            $locked->forceFill([
                'status' => BookingStatus::Confirmed,
                'confirmed_at' => now(),
                'hold_expires_at' => null,
                'paid_cents' => $paid,
                'balance_cents' => max(0, $locked->total_cents - $paid),
                'refunded_cents' => Payment::refundedCentsFor($locked->getKey()),
            ])->save();

            return ['booking' => $locked, 'guaranteed' => $guaranteed];
        });

        // **After commit, never inside.** AVL-46, and every listener is queued
        // (BKG-13) so a failing one cannot roll the confirmation back (BKG-14).
        BookingConfirmed::dispatch($result['booking']->getKey(), (int) $result['booking']->tenant_id);

        if ($result['guaranteed']) {
            DepartureGuaranteed::dispatch(
                (int) $result['booking']->departure_id,
                (int) $result['booking']->tenant_id,
            );
        }

        return $result['booking']->refresh();
    }

    /**
     * Move the seats, and decide `guaranteed` (AVL-43.2, AVL-48).
     *
     * @return bool whether this confirmation is what made the departure guaranteed
     *
     * @throws CapacityExceeded
     */
    private function commitSeats(Booking $booking, Departure $departure, bool $fromCheckout): bool
    {
        $seats = $booking->pax_capacity_total;

        if (! $fromCheckout) {
            // The direct path: BKG-19's zero-total booking, or an operator
            // marking a manual booking paid. The seats are still held, so they
            // move rather than being taken again.
            $ownHeld = $booking->holdsSeats() ? $seats : 0;

            if (! SeatCommitment::commit($departure, $seats, $ownHeld)) {
                // The `WHERE` clause matched nothing: between this transaction
                // reading the row and writing it, the seats went.
                throw CapacityExceeded::forDeparture($seats);
            }
        }

        $departure->refresh();

        $minPax = (int) ($departure->min_pax ?? 0);

        // AVL-48 inside the transaction, because the count it reads is only
        // true in here. AVL-49: once guaranteed, never reversed.
        if ($minPax > 0 && $departure->seats_sold >= $minPax && $departure->status === DepartureStatus::Scheduled) {
            $departure->forceFill(['status' => DepartureStatus::Guaranteed])->save();

            return true;
        }

        return false;
    }
}
