<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The operator made an offer (spec BKG-26).
 *
 * Dispatched after commit, carrying ids rather than models — the lesson #53
 * paid for: a queued listener is constructed on a worker, where a serialised
 * model is re-fetched under whatever tenant the previous job left behind.
 *
 * **Nothing listens yet.** The quote email, with its `/q/{token}` link in the
 * guest's own locale, is #87's; the page it points at is #86's.
 */
final class QuoteSent
{
    use Dispatchable;

    public function __construct(
        public readonly int $quoteId,
        public readonly int $bookingId,
        public readonly int $tenantId,
    ) {}
}
