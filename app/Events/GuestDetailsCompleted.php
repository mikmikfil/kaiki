<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Booking\Actions\SaveGuestDetails;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every passenger on a booking now has the fields the operator asked for
 * (spec OPS-19, `docs/api.md` §8.1).
 *
 * ## It fires on the transition, never on the state
 *
 * {@see SaveGuestDetails::syncStatus()} runs on **every** save of the guest
 * form — a party of six being filled in over three sittings recomputes the
 * status three times, and the last two both find it `complete`. Dispatching
 * whenever the status *is* complete would post the same webhook to somebody's
 * system once per page submit thereafter.
 *
 * So this is dispatched only when the status **changes** to complete, which is
 * the difference between an event and a fact.
 *
 * ## It carries a count, and never the rows
 *
 * OPS-20: a webhook payload never carries a document number, masked or
 * otherwise. The interesting news is *that* the manifest is ready and how many
 * people are on it; the manifest itself is the operator's own export, which is
 * an audited action in the panel for exactly this reason.
 *
 * ## Ids, not a model
 *
 * The rule every event in this directory follows, and the lesson #53 paid for:
 * a queued listener is constructed on a worker, where a serialised model is
 * re-fetched under whatever tenant the previous job left behind.
 */
final class GuestDetailsCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
        /** How many passengers are on the finished manifest. */
        public readonly int $guestCount,
    ) {}
}
