<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Notifications\Actions\SendNotification;
use App\Domain\Notifications\Support\SmsComposer;
use App\Enums\NotificationTemplate;
use App\Events\BookingConfirmed;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * BKG-13.2 and 13.3: the confirmation email, and the text message.
 *
 * ## Queued and independently retryable, because BKG-14 says so
 *
 * *"A failure in any listener MUST NOT roll back the confirmation or block the
 * others."* Each of BKG-13's nine is its own queued listener for exactly that:
 * a Postmark outage must not stop the e-ticket being generated, and neither
 * must stop the booking being confirmed — the money has already moved.
 *
 * That is also why nothing here is wrapped in a transaction. The confirmation
 * committed before this event was dispatched (AVL-46), and a listener that
 * opened one would be able to roll back work that is not its own.
 *
 * ## Ids, not a model
 *
 * The lesson #53 paid for: a queued listener is constructed on a worker, where
 * a serialised model is re-fetched under whatever tenant the previous job left
 * behind. {@see BookingConfirmed} carries integers, and this resolves the
 * tenant first and the booking second, in that order.
 */
class SendBookingConfirmation implements ShouldQueue
{
    /**
     * Three attempts, then the failure feed.
     *
     * BKG-14 requires a failed listener to appear in the operator panel with a
     * retry button, and the row `SendNotification` wrote before the attempt is
     * what makes that possible — a listener that only left an exception behind
     * would give the panel nothing to show.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly SendNotification $notifications) {}

    public function handle(BookingConfirmed $event): void
    {
        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($event->tenantId),
        );

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($event): void {
            $booking = Booking::query()->find($event->bookingId);

            if (! $booking instanceof Booking) {
                return;
            }

            $this->notifications->mail(
                $booking,
                NotificationTemplate::BookingConfirmed,
                new GuestMail($booking, NotificationTemplate::BookingConfirmed),
            );

            $this->sendSms($booking);
        });
    }

    /**
     * BKG-13.3: *"if the tenant has SMS enabled and the phone is valid."*
     *
     * Both halves are decided elsewhere — the tenant's choice by
     * `SmsGatewayResolver` (whose fallback composes and drops rather than
     * failing), and the phone by `SendNotification::sms()`, which records
     * nothing at all when there is no number. Neither is a reason to skip the
     * composition, because the segment count is what NTF-5 wants the operator
     * warned about and it is the same either way.
     */
    private function sendSms(Booking $booking): void
    {
        $meetingPoint = (string) ($booking->product === null ? '' : ($booking->product->meetingPoint->name ?? ''));
        $when = $booking->local_date->format('d/m') . ' ' . substr((string) $booking->local_time, 0, 5);

        $body = SmsComposer::compose(
            lead: __('mail.booking_confirmed.sms'),
            meetingPoint: $meetingPoint,
            when: $when,
            link: route('guest.booking', ['token' => $booking->manage_token]),
            maxSegments: (int) config('kaiki.notifications.sms_max_segments', 2),
        );

        $this->notifications->sms($booking, NotificationTemplate::BookingConfirmed, $body);
    }
}
