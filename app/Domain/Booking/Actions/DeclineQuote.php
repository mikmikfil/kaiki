<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\QuoteStatus;
use App\Events\QuoteDeclined;
use App\Models\Booking;
use App\Models\Quote;
use App\Models\VesselBlock;
use Illuminate\Support\Facades\DB;

/**
 * The guest saying no (spec BKG-26, `docs/data-model.md` §4.4).
 *
 * ## `quote_declined` is its own cancel reason, and #85 had to add it
 *
 * BKG-26 names it — *"Declining transitions to `cancelled` with reason
 * `quote_declined`"* — and §2.5's enumeration of `bookings.cancel_reason` did
 * not have it. Folding it into `guest_request` would have been invisible and
 * would have destroyed the one number a quote-mode operator most wants: how
 * many of my quotes get turned down. That is a pricing signal, and a guest
 * cancelling a confirmed booking is not.
 *
 * ## The boat goes back on sale immediately
 *
 * If the operator opted to hold the window when they sent (BKG-25), that block
 * is released here rather than left to expire with `valid_until`. A guest who
 * has said no has said no, and a boat sitting unsellable for the rest of a
 * fortnight because somebody declined on day one is the exact failure BKG-25's
 * resolution exists to prevent.
 *
 * ## Nothing is refunded, because nothing was paid
 *
 * A declined quote's booking never reached `pending_payment`, so there is no
 * payment row and no entitlement. It is cancelled through a direct write rather
 * than through {@see CancelBooking}, which would compute a refund from a
 * `policy_snapshot` that does not exist yet — CXL-2 writes it at *acceptance*.
 */
final class DeclineQuote
{
    /** @param  string|null  $reason  the guest's own words; stored verbatim */
    public function __invoke(Quote $quote, ?string $reason = null): Quote
    {
        $declined = DB::transaction(function () use ($quote, $reason): Quote {
            /** @var Quote $locked */
            $locked = Quote::query()->lockForUpdate()->findOrFail($quote->getKey());

            if ($locked->status !== QuoteStatus::Sent) {
                // Idempotent by status: a second click, or a decline racing the
                // expiry sweeper. Whichever got here first wins.
                return $locked;
            }

            $locked->forceFill([
                'status' => QuoteStatus::Declined,
                'declined_at' => now(),
                // Truncated to the column, not rejected: a guest who wrote an
                // essay has still declined, and refusing the decline because
                // the reason was long would be absurd.
                'decline_reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            ])->save();

            /** @var Booking $booking */
            $booking = Booking::query()->lockForUpdate()->findOrFail($locked->booking_id);

            if ($booking->status->canTransitionTo(BookingStatus::Cancelled)) {
                $booking->forceFill([
                    'status' => BookingStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => CancelledBy::Guest,
                    'cancel_reason' => CancelReason::QuoteDeclined,
                ])->save();
            }

            // Back on sale now, not in a fortnight. See the class docblock.
            VesselBlock::query()->where('booking_id', $booking->getKey())->delete();

            return $locked;
        });

        QuoteDeclined::dispatch($declined->getKey(), $declined->booking_id, $declined->tenant_id);

        return $declined->refresh();
    }
}
