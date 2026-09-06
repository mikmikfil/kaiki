<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Booking\Actions\ExpireQuotes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The scheduler's handle on {@see ExpireQuotes} (spec BKG-26).
 *
 * A job rather than a command, matching `ExpireStaleHoldsJob` and
 * `ExpireAbandonedCheckoutsJob`: the schedule entry is a queued dispatch, so a
 * slow sweep never blocks the scheduler tick, and the Action itself stays a
 * plain class a test can call directly.
 */
class ExpireQuotesJob implements ShouldQueue
{
    use Queueable;

    /** @return int how many quotes were expired */
    public function handle(ExpireQuotes $expire): int
    {
        return $expire();
    }
}
