<?php

declare(strict_types=1);

namespace App\Jobs\Reminders;

use App\Domain\Notifications\Actions\SendPaymentUnfinished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** «Η κράτησή σας για … δεν ολοκληρώθηκε», as a sweep (2026-09-24). */
class SendPaymentUnfinishedJob implements ShouldQueue
{
    use Queueable;

    public function handle(SendPaymentUnfinished $send): int
    {
        return $send();
    }
}
