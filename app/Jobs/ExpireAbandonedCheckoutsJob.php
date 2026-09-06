<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Booking\Actions\ExpireAbandonedCheckouts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * BKG-10's sweeper: seats back from checkouts nobody finished.
 *
 * ## Unlike the hold sweeper, this one *is* load-bearing
 *
 * `ExpireStaleHoldsJob` is a tidier — every availability read reaches the right
 * answer on its own, so a worker outage costs an inaccurate dashboard. This one
 * has no read-side twin: a `pending_payment` booking's seats are in
 * `seats_sold`, which is exactly the number that must be believed, and nothing
 * infers "but that guest left an hour ago".
 *
 * That asymmetry follows from BKG-9 committing at redirect rather than at the
 * webhook. It is the cost of closing the window where a guest on the gateway
 * page can lose the seat they are paying for, and it is why this job runs every
 * five minutes rather than hourly.
 *
 * Five, not one: an abandoned checkout is minutes old at best and the cutoff is
 * an hour, so a minute-by-minute sweep would be twelve times the queries for no
 * seat freed any sooner.
 */
class ExpireAbandonedCheckoutsJob implements ShouldQueue
{
    use Queueable;

    /**
     * At most this many per run.
     *
     * A bound rather than "all of them", for the reason the hold sweeper gives:
     * a queue that has been down comes back to a backlog, and one job holding a
     * connection through all of it is a worse outage than the one it recovers.
     */
    public const BATCH = 250;

    public function handle(ExpireAbandonedCheckouts $expire): void
    {
        $expire(self::BATCH);
    }
}
