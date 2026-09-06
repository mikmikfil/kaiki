<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The guest said yes, and the booking now has a price (spec BKG-26).
 *
 * The moment CXL-2 names for a quote booking: both snapshots are frozen and the
 * booking is in `pending_payment`. What has **not** happened is the gateway
 * session — `StartCheckout` makes an external call and AVL-46 keeps it out of
 * the transaction this event follows.
 *
 * **Nothing listens yet.** The operator notification is #87's.
 */
final class QuoteAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly int $quoteId,
        public readonly int $bookingId,
        public readonly int $tenantId,
    ) {}
}
