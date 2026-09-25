<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationTemplate;
use App\Events\LatePaymentRefunded;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * «Η πληρωμή σας επιστρέφεται», with the reason (2026-09-25).
 *
 * A guest charged after their booking could no longer take the money was
 * refunded without a word, and the operator found out from the phone call.
 * One email per late refund: not deduplicated by template, because a second
 * late charge on the same booking is a second refund the guest should hear
 * about; the event itself fires once per refund row.
 */
class SendLatePaymentRefund implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly SendNotification $notifications) {}

    public function handle(LatePaymentRefunded $event): void
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
                NotificationTemplate::PaymentRefunded,
                new GuestMail($booking, NotificationTemplate::PaymentRefunded, [
                    'amount_cents' => $event->amountCents,
                    'reason' => $event->reason,
                ]),
                once: false,
            );
        });
    }
}
