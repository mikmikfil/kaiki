<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationTemplate;
use App\Events\WeatherChoiceReminderDue;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * CXL-7's reminder, 72 hours into an unanswered weather choice
 * (product owner, 2026-09-17).
 *
 * `ApplyWeatherChoiceDefaults` already claimed the reminder with a conditional
 * update and dispatched this event exactly once per booking; nothing listened,
 * so `weather_choice_reminded_at` recorded a reminder no guest received.
 *
 * Skipped if the guest has chosen in the meantime — the queue can be slower
 * than a guest who opened the first email.
 */
class SendWeatherChoiceReminder implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly SendNotification $notifications) {}

    public function handle(WeatherChoiceReminderDue $event): void
    {
        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($event->tenantId),
        );

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($event): void {
            $booking = Booking::query()->find($event->bookingId);

            if (! $booking instanceof Booking || trim((string) $booking->guest_email) === '' || $booking->weather_choice !== null) {
                return;
            }

            $this->notifications->mail(
                $booking,
                NotificationTemplate::WeatherChoiceReminder,
                new GuestMail($booking, NotificationTemplate::WeatherChoiceReminder, [
                    'due_at' => $event->dueAt,
                ]),
            );
        });
    }
}
