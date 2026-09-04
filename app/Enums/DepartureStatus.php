<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a departure stands (`docs/data-model.md` §2.4).
 *
 * **Cancellation is a status, never a delete.** Bookings must keep resolving
 * their `departure_id` to render a guest's history, and a cancelled trip a
 * guest paid for is a row that has to survive.
 *
 * `guaranteed` is the operator's promise, reached when `seats_sold` meets the
 * product's `min_pax` — which is why holds are kept out of `seats_sold` (§2.4):
 * an unpaid draft must not flip a departure to guaranteed and send everyone an
 * email saying the trip is confirmed.
 */
enum DepartureStatus: string
{
    use HasTranslatedLabel;

    /** Sellable, but not yet certain to sail. */
    case Scheduled = 'scheduled';

    /** Enough seats sold that the operator has committed to sailing. */
    case Guaranteed = 'guaranteed';

    /** Called off. The row stays; bookings still resolve to it. */
    case Cancelled = 'cancelled';

    /** Sailed and finished, set by the nightly completion sweep. */
    case Completed = 'completed';

    /** Can a guest still book onto this departure? */
    public function isSellable(): bool
    {
        return $this === self::Scheduled || $this === self::Guaranteed;
    }

    /** Does this departure still occupy its vessel's calendar? */
    public function occupiesVessel(): bool
    {
        return $this !== self::Cancelled;
    }
}
