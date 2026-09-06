<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sailing has enough passengers to go (spec AVL-48, AVL-49).
 *
 * The operator's promise, and the reason `seats_held` is kept out of
 * `seats_sold`: an unpaid draft must never trip this and email everybody that
 * their trip is confirmed.
 *
 * ## It is never reversed
 *
 * AVL-49, marked RESOLVED with its reasoning attached: a later cancellation
 * dropping the count back below `min_pax` does **not** un-guarantee the
 * departure, because *"reversing it would retract a promise already made to
 * guests by email."* Only the operator cancelling the sailing outright ends it.
 *
 * That is why this event exists rather than the state being derived on read: a
 * derived flag would flicker back off the moment somebody cancelled, and the
 * guests who were told would never hear about it.
 *
 * Dispatched after commit, alongside {@see BookingConfirmed}, and carrying ids
 * for the same reason.
 */
final class DepartureGuaranteed
{
    use Dispatchable;

    public function __construct(
        public readonly int $departureId,
        public readonly int $tenantId,
    ) {}
}
