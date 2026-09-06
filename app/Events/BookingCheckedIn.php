<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Booking\Actions\CheckInGuest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * At least one person on this booking is aboard (spec BKG-21).
 *
 * > **BKG-21** `confirmed` transitions to `checked_in` when **at least one**
 * > guest on the booking is checked in.
 *
 * ## Fired once, on the transition, not once per guest
 *
 * A family of four produces one of these, on the first scan. The per-guest fact
 * lives on `booking_guests.checked_in_at`; this event is about the *booking*
 * changing state, and a listener that wanted to know "is anybody aboard yet"
 * would otherwise have to ignore three duplicates.
 *
 * {@see CheckInGuest} dispatches it after the transaction commits, for AVL-46's
 * reason — this is the event a manifest export or an outbound webhook will hang
 * off, and both are external calls.
 *
 * ## Ids, not a model
 *
 * The lesson #53 paid for, and the same shape as {@see BookingConfirmed}.
 */
final class BookingCheckedIn
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
    ) {}
}
