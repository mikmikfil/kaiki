<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The operator's inbox (`docs/data-model.md` §2.5, spec BKG-28).
 *
 * ## `spam` is a status rather than a deletion
 *
 * §2.5: *"`status = spam` rows are excluded from counts and purged after 30
 * days."* Deleting on receipt would make the honeypot and the timing check
 * unreviewable — an operator who suspects a real enquiry was thrown away has
 * nowhere to look, and neither does anybody tuning the filters. Thirty days is
 * long enough to notice and short enough that a spam run is not kept for years.
 *
 * ## `converted` points at the booking it became
 *
 * The one status that carries a foreign key. An enquiry that turned into a
 * booking is the outcome an operator most wants counted, and a `closed` row
 * with a note saying so is not a number anybody can sum.
 */
enum EnquiryStatus: string
{
    use HasTranslatedLabel;

    case New = 'new';
    case InProgress = 'in_progress';
    case Answered = 'answered';

    /** Became a booking; `converted_booking_id` says which. */
    case Converted = 'converted';

    /** Caught by the honeypot or the timing check — kept for review. */
    case Spam = 'spam';

    case Closed = 'closed';

    /** Does this row count towards the operator's open workload? */
    public function isOpen(): bool
    {
        return $this === self::New || $this === self::InProgress;
    }

    /** Excluded from every count on the dashboard (§2.5). */
    public function isSpam(): bool
    {
        return $this === self::Spam;
    }
}
