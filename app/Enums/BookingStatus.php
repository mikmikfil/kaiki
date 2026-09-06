<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Models\Booking;

/**
 * Where a booking stands (`docs/data-model.md` §4.1).
 *
 * ## The two counters this enum decides between
 *
 * `CLAUDE.md`'s first engine invariant: `seats_sold` and `seats_held` are
 * **disjoint and additive**, and this enum is what sorts a booking into one or
 * the other. {@see self::holdsSeats()} is `seats_held` — a `draft` with an
 * unexpired hold, nothing else. {@see self::committingSeats()} is `seats_sold`
 * — pax that have moved from held to sold at **checkout start**, not at the
 * payment webhook.
 *
 * Getting that boundary wrong in either direction breaks something visible: put
 * `draft` into `seats_sold` and an unpaid browser tab flips a departure to
 * `guaranteed` and emails every guest that the trip is confirmed; leave
 * `pending_payment` out of it and a guest sitting on the gateway page loses
 * their seat to somebody who started later.
 *
 * ## Transitions live here, not in the Actions
 *
 * §4's convention: the allowed map is on the enum so it is testable in
 * isolation, and every Action asserts against it. A transition table spread
 * across eight Actions is a table nobody can read.
 */
enum BookingStatus: string
{
    use HasTranslatedLabel;

    /** Created, held, unpaid. The row *is* the hold (ADR-0005). */
    case Draft = 'draft';

    /** A quote-mode request, before the operator has priced it. */
    case QuoteRequested = 'quote_requested';

    /** The operator has sent a priced quote; the guest has not answered. */
    case QuoteSent = 'quote_sent';

    /** Checkout started: the guest is at the gateway and the seats are committed. */
    case PendingPayment = 'pending_payment';

    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /** The hold ran out, or a quote request went stale. Never reused. */
    case Expired = 'expired';

    /**
     * Does a booking in this status occupy `seats_held`?
     *
     * `draft` alone — and only while `hold_expires_at` is in the future, which
     * is a fact about the row rather than about the status, so callers pair
     * this with that check. {@see Booking::holdsSeats()} does both.
     */
    public function holdsSeats(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Does a booking in this status occupy `seats_sold`?
     *
     * BKG-9, marked RESOLVED with its reasoning: committing at redirect rather
     * than at the webhook closes the gap where a guest is on the gateway page
     * while somebody else takes the last seat.
     */
    public function committingSeats(): bool
    {
        return match ($this) {
            self::PendingPayment, self::Confirmed, self::CheckedIn, self::Completed => true,
            default => false,
        };
    }

    /** Is this the end of the line? */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Refunded, self::Expired => true,
            default => false,
        };
    }

    /** Has this booking been paid for, or is it on its way to being? */
    public function isLive(): bool
    {
        return match ($this) {
            self::Cancelled, self::Refunded, self::Expired => false,
            default => true,
        };
    }

    /**
     * The §4.1 transition table, in one place.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingPayment, self::Confirmed, self::Expired, self::Cancelled],
            self::QuoteRequested => [self::QuoteSent, self::Cancelled, self::Expired],
            self::QuoteSent => [self::PendingPayment, self::Confirmed, self::Cancelled, self::Expired],
            // Back to `draft` on a failed payment, not to `expired`: the guest is
            // still there and still wants the seat, and the hold may well have
            // time left on it.
            self::PendingPayment => [self::Confirmed, self::Draft, self::Expired, self::Cancelled],
            self::Confirmed => [self::CheckedIn, self::Completed, self::Cancelled, self::Refunded],
            self::CheckedIn => [self::Completed, self::Cancelled],
            self::Completed => [self::Refunded],
            self::Cancelled => [self::Refunded],
            self::Refunded, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
