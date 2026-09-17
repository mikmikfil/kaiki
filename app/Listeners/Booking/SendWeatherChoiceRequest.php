<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationTemplate;
use App\Events\WeatherChoiceRequested;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * CXL-7's question to the guest: refund, voucher or another date
 * (product owner, 2026-09-17).
 *
 * The template and the event both existed and nothing joined them, so a guest
 * whose trip the weather cancelled was owed a decision they were never asked
 * for — and fourteen days later the operator's default was applied to money
 * they did not know about. `SendBookingCancellation` skips weather precisely
 * because this message is the one that goes instead.
 *
 * The event carries the entitlement and the deadline as they were when the
 * choice opened, so the email says the same figure and date as the booking
 * page. Once per booking: a departure cancelled for weather cancels a booking
 * once. Queued and retried like the confirmation, for BKG-14's reason.
 */
class SendWeatherChoiceRequest implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly SendNotification $notifications) {}

    public function handle(WeatherChoiceRequested $event): void
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
                NotificationTemplate::WeatherChoiceRequested,
                new GuestMail($booking, NotificationTemplate::WeatherChoiceRequested, [
                    'entitlement_cents' => $event->entitlementCents,
                    'due_at' => $event->dueAt,
                ]),
            );
        });
    }
}
