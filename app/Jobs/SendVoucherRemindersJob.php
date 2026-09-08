<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Pricing\Actions\SendVoucherExpiryReminders;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The nightly voucher-expiry sweep (OPS-16).
 *
 * Daily rather than the quarter-hourly cadence `SendDueRemindersJob` runs at,
 * and the difference is what each is measuring. A balance-due reminder is
 * counted in hours against a departure; a voucher expiry is counted in days
 * against a date, and a message about a month from now does not get better for
 * being sent at 09:15 instead of 09:00.
 *
 * It also keeps a slow query off a job that runs ninety-six times a day.
 */
class SendVoucherRemindersJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(SendVoucherExpiryReminders $reminders): void
    {
        $reminders();
    }
}
