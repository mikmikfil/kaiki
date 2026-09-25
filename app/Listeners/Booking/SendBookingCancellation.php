<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\CancelReason;
use App\Enums\NotificationTemplate;
use App\Events\BookingCancelled;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The «your booking is cancelled» email (product owner, 2026-09-17).
 *
 * The template existed from M2 and nothing sent it, so a guest whose departure
 * the operator called off learned about it on the quay. Every cancellation
 * that ends a trip the guest was expecting now sends it: the guest's own, the
 * operator's from the panel, a departure cancelled from under them, and the
 * API's.
 *
 * **Except weather.** A weather cancellation is not settled yet — the guest is
 * sent `weather_choice_requested` instead, which asks refund, voucher or
 * rebook, and a second email saying "cancelled" beside it would read as the
 * choice having been made for them.
 *
 * Queued and retried like the confirmation, for BKG-14's reason: a mail outage
 * must not undo a cancellation that has already released the seats.
 */
class SendBookingCancellation implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly SendNotification $notifications) {}

    public function handle(BookingCancelled $event): void
    {
        if ($event->reason === CancelReason::Weather) {
            return;
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($event->tenantId),
        );

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($event): void {
            $booking = Booking::query()->find($event->bookingId);

            // Only a booking the guest was told they had: a draft, a quote
            // request or an unpaid checkout was never "your booking".
            if (! $booking instanceof Booking || trim((string) $booking->guest_email) === '' || ! $booking->wasBooked()) {
                return;
            }

            $this->notifications->mail(
                $booking,
                NotificationTemplate::BookingCancelled,
                new GuestMail($booking, NotificationTemplate::BookingCancelled, [
                    'refunded_cents' => $event->refundCents,
                ]),
            );
        });
    }
}
