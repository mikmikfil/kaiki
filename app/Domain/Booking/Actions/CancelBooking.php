<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Data\RefundOverride;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Domain\Booking\Support\SeatCommitment;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\PaymentStatus;
use App\Enums\RefundMethod;
use App\Events\BookingCancelled;
use App\Events\RefundOverridden;
use App\Jobs\ExecuteGatewayRefund;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ending a booking, and settling what it owes (spec CXL-3, CXL-5, CXL-9).
 *
 * ## The entitlement is computed *before* the transaction, from a frozen policy
 *
 * CXL-1, and the ordering is deliberate. {@see RefundEntitlement} reads
 * `bookings.policy_snapshot` and nothing else — no `CancellationPolicy` model,
 * no id it could load — so the figure is the one the guest agreed to in June
 * however many times the operator has edited the policy since. Computing it
 * outside the lock also keeps AVL-46 honest: the transaction below holds a
 * departure's row and does arithmetic, not lookups.
 *
 * ## Capacity comes back in the same transaction as the status
 *
 * CXL-9, exactly. A cancellation that released seats and then failed to write
 * the status would put the same pax on sale twice; one that wrote the status
 * and failed to release would take the seats off sale forever. They are the
 * same write or they are a bug.
 *
 * The lock order is AVL-45's — vessel, departure, booking, voucher — and
 * `LockDisciplineTest` is what makes that a fact rather than an intention. It
 * caught a real deadlock in {@see ExpireAbandonedCheckouts}, where locking the
 * booking first raced a confirmation over the same sailing.
 *
 * ## Nothing here talks to a gateway
 *
 * AVL-46. The cash half becomes a `pending` refund row and a queued job
 * ({@see ExecuteGatewayRefund}); the row exists **before** the call,
 * for PAY-9's reason — the dangerous retry is the one where no response ever
 * came back, and a key derived from a response cannot deduplicate the request
 * that produced it. The voucher half is a database write and settles inline.
 *
 * ## An override is not a different code path
 *
 * CXL-5 changes the *percentage* and the *method*, and both feed the same
 * calculation. What it adds is an `override.applied` audit row carrying the
 * operator's reason, dispatched whether or not any money moves — a waiver moves
 * none and is the override most likely to be questioned a year later.
 */
final class CancelBooking
{
    public function __construct(
        private readonly RefundBooking $refundBooking,
    ) {}

    /**
     * @param  CancelReason  $reason  why, from the fixed list — these get counted
     * @param  RefundOverride|null  $override  CXL-5; carries its own mandatory reason
     * @param  Carbon|null  $at  the instant the tier is measured from; now by default
     * @param  bool  $settle  false leaves the entitlement unpaid — CXL-7's weather
     *                        path, where the guest has a choice to make first
     *
     * @throws RuntimeException when the booking cannot be cancelled from where it is
     */
    public function __invoke(
        Booking $booking,
        CancelReason $reason = CancelReason::GuestRequest,
        CancelledBy $by = CancelledBy::Guest,
        ?RefundOverride $override = null,
        ?Carbon $at = null,
        bool $settle = true,
    ): Booking {
        $at ??= now();

        if ($booking->status === BookingStatus::Cancelled) {
            // Idempotent by status, the same posture AVL-47 takes for webhooks.
            // A second cancel must not release the seats twice, and releasing
            // twice is how a departure ends up with negative `seats_sold`.
            return $booking;
        }

        if (! $booking->status->canTransitionTo(BookingStatus::Cancelled)) {
            throw new RuntimeException(
                "A booking in {$booking->status->value} cannot be cancelled (§4.1).",
            );
        }

        $policy = RefundEntitlement::forCancellation($booking, $at);

        $entitlement = $override === null
            ? $policy
            : RefundEntitlement::atPercent($booking, $override->percentAgainst($policy->percent));

        $method = $override === null ? RefundMethod::Cash : $override->method;

        $cancelled = DB::transaction(function () use ($booking, $reason, $by, $at): Booking {
            // AVL-45's order — vessel, departure, booking.
            // `App\Domain\Booking\Support\LockOrder::RANK` is what
            // `LockDisciplineTest` reads, and this file is on its list.
            if ($booking->vessel_id !== null) {
                Vessel::query()->lockForUpdate()->find($booking->vessel_id);
            }

            $departure = $booking->departure_id === null
                ? null
                : Departure::query()->lockForUpdate()->find($booking->departure_id);

            /** @var Booking $locked */
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());

            // Re-checked under the lock rather than in the selecting read: a
            // webhook or the abandoned-checkout sweeper may have landed in
            // between, and whichever got the lock first wins.
            if ($locked->status === BookingStatus::Cancelled) {
                return $locked;
            }

            $this->releaseCapacity($locked, $departure);

            // Any open payment goes with the booking. Leaving one `pending`
            // would sit in the operator's stuck-payment feed forever, competing
            // for attention with the ones that mean something.
            Payment::query()
                ->where('booking_id', $locked->getKey())
                ->open()
                ->update(['status' => PaymentStatus::Cancelled->value, 'updated_at' => now()]);

            $locked->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => $at,
                'cancelled_by' => $by,
                'cancel_reason' => $reason,
                // A cancelled booking holds nothing. The column is what the
                // availability read path checks, and a stale future value would
                // keep the seats notionally held by a booking that has ended.
                'hold_expires_at' => null,
            ])->save();

            return $locked;
        });

        if ($override !== null) {
            // CXL-5. Dispatched after commit like everything else, and *before*
            // the refund, so the reason is on the trail even if the gateway
            // call later fails.
            RefundOverridden::dispatch($cancelled, $override, $policy->percent);
        }

        // AVL-46: the gateway call is a queued job, and the email is a listener.
        //
        // `$settle` is false on exactly one path: CXL-7's weather cancellation,
        // where the trip has ended and the money has **not** been decided. The
        // entitlement is real and waiting on the guest's answer, and refunding
        // it here would answer for them.
        $moved = $settle
            ? ($this->refundBooking)($cancelled, $entitlement, $method, $override?->reason)
            : 0;

        BookingCancelled::dispatch(
            $cancelled->getKey(),
            $cancelled->tenant_id,
            $reason,
            $moved,
        );

        return $cancelled->refresh();
    }

    /**
     * CXL-9's two modes, both inside the caller's transaction.
     *
     * A seat booking gives its pax back to `departures.seats_sold`; a private
     * charter stops occupying its vessel window the moment its status stops
     * being live, which {@see Booking::occupiesVesselWindow()} already decides — so
     * there is nothing to release for one, and everything for the other.
     *
     * The `VesselBlock` sweep is the case CXL-9 names and the product does not
     * yet produce: no code path writes a block carrying a `booking_id` today.
     * It is here because the alternative is remembering to add it in the
     * milestone that does, and the query costs one indexed read on a path that
     * runs once per cancellation.
     */
    private function releaseCapacity(Booking $booking, ?Departure $departure): void
    {
        if ($departure instanceof Departure && $booking->status->committingSeats()) {
            SeatCommitment::release($departure, $booking->pax_capacity_total);
        }

        VesselBlock::query()
            ->where('booking_id', $booking->getKey())
            ->get()
            ->each(static fn (VesselBlock $block): ?bool => $block->delete());
    }
}
