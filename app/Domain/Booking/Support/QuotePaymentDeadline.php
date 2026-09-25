<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Enums\QuoteStatus;
use App\Models\Booking;
use App\Models\Quote;
use Carbon\CarbonInterface;

/**
 * Until when an accepted quote can be paid for online (2026-09-25).
 *
 * `AcceptQuote` leaves the booking in `pending_payment` with no card page open:
 * a sale waiting for the guest's money, not a checkout somebody walked away
 * from. So the abandoned-checkout sweeper's sixty minutes do not apply to it.
 * It stays payable at `/c/{token}` until the later of the quote's own validity
 * and `kaiki.booking.accepted_quote_payment_days` after acceptance — and never
 * past the moment the trip starts.
 */
final class QuotePaymentDeadline
{
    /** Null when the booking is not one an accepted quote made. */
    public static function for(Booking $booking): ?CarbonInterface
    {
        /** @var Quote|null $quote */
        $quote = Quote::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', QuoteStatus::Accepted->value)
            ->latest('id')
            ->first();

        if (! $quote instanceof Quote) {
            return null;
        }

        $acceptedAt = $quote->accepted_at ?? $quote->updated_at ?? now();
        $deadline = $acceptedAt->copy()->addDays((int) config('kaiki.booking.accepted_quote_payment_days', 3));

        if ($quote->valid_until->greaterThan($deadline)) {
            $deadline = $quote->valid_until->copy();
        }

        return $deadline->greaterThan($booking->starts_at_utc)
            ? $booking->starts_at_utc->copy()
            : $deadline;
    }
}
