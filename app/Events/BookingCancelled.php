<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Booking\Actions\CancelBooking;
use App\Enums\CancelReason;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking has ended and its seats are back on sale (spec CXL-9).
 *
 * ## After commit, and for the same reason as every other one
 *
 * AVL-46. {@see CancelBooking} releases capacity and writes the status inside a
 * transaction that holds the departure's row lock; the email, the myDATA
 * cancellation invoice and the operator webhook all happen out here, where a
 * gateway timing out costs a retry rather than the whole boat.
 *
 * ## Ids and scalars, never a model
 *
 * #53's lesson: a queued listener is constructed on a worker, where a
 * serialised model is re-fetched under whatever tenant the previous job left
 * behind. `refundCents` travels as a number because by the time a listener
 * reads it the refund may already have settled and changed `paid_cents` — the
 * figure the guest was told is the figure at cancellation, not the one a later
 * query happens to produce.
 *
 * **Nothing listens yet.** The guest email is #87.
 */
final class BookingCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
        public readonly CancelReason $reason,
        public readonly int $refundCents,
    ) {}
}
