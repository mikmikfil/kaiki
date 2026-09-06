<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Seventy-two hours and no answer (spec CXL-7).
 *
 * The single reminder CXL-7 asks for, dispatched once per booking — the once is
 * `bookings.weather_choice_reminded_at`, because `notification_logs` (§6 item
 * 40) is #87's and a reminder whose idempotency depends on a table nobody has
 * built yet goes out every hour.
 *
 * **Nothing listens yet.** The email is #87.
 */
final class WeatherChoiceReminderDue
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
        public readonly Carbon $dueAt,
    ) {}
}
