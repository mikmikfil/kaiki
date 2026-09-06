<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Booking\Actions\ExpireStaleHolds;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The every-minute hold sweeper (spec AVL-38, ADR-0005).
 *
 * ## It is the tidier, not the guarantee
 *
 * Worth repeating at the job level, because a job scheduled every minute looks
 * load-bearing and this one is not. `Booking::holdsSeats()` decides whether a
 * hold is alive, every availability read applies that on its own, and this job
 * brings `departures.seats_held` back into line afterwards. A worker outage
 * costs an operator an inaccurate dashboard; it cannot cost a guest their seat.
 *
 * `HoldExpiryTest` proves that ordering by asserting the read-side release with
 * this job **never run**.
 *
 * ## Queued rather than a command
 *
 * ADR-0005 says *"a scheduled `ExpireStaleHolds` job runs every minute"*, and
 * queueing it keeps the scheduler's own tick free: a minute-by-minute schedule
 * that runs work inline delays every later entry by however long the work takes.
 * On a backlogged queue that means holds are tidied late, which is exactly the
 * degradation the read-side rule is there to absorb.
 */
class ExpireStaleHoldsJob implements ShouldQueue
{
    use Queueable;

    /**
     * At most this many holds per run.
     *
     * A bound rather than "all of them": a queue that has been down for an hour
     * comes back to a large backlog, and one job holding a connection while it
     * releases ten thousand holds is a worse outage than the one it is
     * recovering from. The next tick is sixty seconds away.
     */
    public const BATCH = 500;

    public function handle(ExpireStaleHolds $expire): void
    {
        $expire(self::BATCH);
    }
}
