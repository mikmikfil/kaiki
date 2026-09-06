<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Booking\Actions\ConfirmBooking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booking is paid for and the seats are the guest's (spec BKG-13, BKG-14).
 *
 * ## Dispatched after commit, never inside
 *
 * AVL-46: a transaction holding a row lock performs no external call. Every one
 * of BKG-13's nine listeners does one — a PDF, an email, an SMS, a myDATA
 * invoice, a webhook — and a lock held across somebody else's HTTP timeout is
 * the whole boat off sale for thirty seconds.
 *
 * {@see ConfirmBooking} therefore dispatches this **after** `DB::transaction()`
 * returns, and BKG-14 is the other half: a failing listener must not roll the
 * confirmation back or block the other eight. Queued and independently
 * retryable is what makes that true.
 *
 * ## Ids, not a model
 *
 * The lesson #53 paid for. A queued listener is constructed on the worker,
 * where a `SerializesModels` payload is re-fetched inside whatever tenant
 * context the previous job left behind — a cross-tenant read the isolation gate
 * exists to prevent. Plain integers force the listener to resolve the tenant
 * first and the booking second, in that order.
 *
 * **Nothing listens yet.** The nine listeners are #87 and #88; this issue
 * asserts the event is *dispatched*, which is the contract those issues plug
 * into.
 */
final class BookingConfirmed
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
    ) {}
}
