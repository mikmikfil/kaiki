<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationTemplate;
use App\Events\BookingGuestsRemoved;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * «Your booking has changed», after people were taken off it (2026-09-17).
 *
 * Sent every time, not once per booking: a party can shrink twice, and the
 * second change is as much news to the guest as the first. The email shows the
 * booking as it now stands, so there is nothing to carry but the ids.
 */
class SendBookingChange implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly SendNotification $notifications) {}

    public function handle(BookingGuestsRemoved $event): void
    {
        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($event->tenantId),
        );

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($event): void {
            $booking = Booking::query()->find($event->bookingId);

            if (! $booking instanceof Booking || trim((string) $booking->guest_email) === '') {
                return;
            }

            $this->notifications->mail(
                $booking,
                NotificationTemplate::BookingChanged,
                new GuestMail($booking, NotificationTemplate::BookingChanged),
                once: false,
            );
        });
    }
}
