<?php

declare(strict_types=1);

namespace App\Jobs\Reminders;

use App\Domain\Availability\Actions\SendCrewReminders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** The crew's 24-hours-before reminder, as a sweep (2026-09-24). */
class SendCrewRemindersJob implements ShouldQueue
{
    use Queueable;

    public function handle(SendCrewReminders $reminders): int
    {
        return $reminders();
    }
}
