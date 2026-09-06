<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The guest read the offer and said no (spec BKG-26).
 *
 * `decline_reason` is deliberately **not** carried here. It is guest-written
 * free text, it is stored on the quote where the operator can read it, and
 * putting it in an event payload would put it into a queue payload and then
 * into whatever a listener logs. The operator's own screen is the right place
 * for a guest's words.
 *
 * **Nothing listens yet.** The operator notification is #87's.
 */
final class QuoteDeclined
{
    use Dispatchable;

    public function __construct(
        public readonly int $quoteId,
        public readonly int $bookingId,
        public readonly int $tenantId,
    ) {}
}
