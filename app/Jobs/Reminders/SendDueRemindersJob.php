<?php

declare(strict_types=1);

namespace App\Jobs\Reminders;

use App\Domain\Notifications\Actions\SendDueReminders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The scheduler's handle on BKG-16's sweep.
 *
 * A job rather than a command, matching `ExpireStaleHoldsJob` and its
 * neighbours: the schedule entry is a queued dispatch, so a slow sweep never
 * blocks the scheduler tick, and the Action stays a plain class a test can call
 * directly with a fixed `$now`.
 */
class SendDueRemindersJob implements ShouldQueue
{
    use Queueable;

    /** @return int how many messages were put in motion */
    public function handle(SendDueReminders $reminders): int
    {
        return $reminders();
    }
}
