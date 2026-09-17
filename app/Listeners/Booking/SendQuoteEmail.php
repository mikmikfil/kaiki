<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationTemplate;
use App\Enums\QuoteStatus;
use App\Events\QuoteSent;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Quote;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * BKG-26's email: the operator's offer, with what it includes, what it costs,
 * until when, and the link to accept it (product owner, 2026-09-17).
 *
 * `SendQuote` has dispatched {@see QuoteSent} since #86 with a note that the
 * mail was #87's, and #87 never wired it — so «Send quote» on the panel sent
 * nothing, and the guest learned of the offer only if the operator also
 * phoned.
 *
 * ## Every version, not once per booking
 *
 * `once: false`. A revised quote is a new version on the same booking, and the
 * guest needs the new link: the old one now says «replaced». The dedupe that
 * matters is that `SendQuote` dispatches once per version, under a lock.
 *
 * A version superseded before the queue reached it is skipped, so a quick
 * correction does not send the wrong offer first.
 */
class SendQuoteEmail implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly SendNotification $notifications) {}

    public function handle(QuoteSent $event): void
    {
        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($event->tenantId),
        );

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($event): void {
            $quote = Quote::query()->find($event->quoteId);
            $booking = Booking::query()->find($event->bookingId);

            if (! $quote instanceof Quote || $quote->status !== QuoteStatus::Sent) {
                return;
            }

            if (! $booking instanceof Booking || trim((string) $booking->guest_email) === '') {
                return;
            }

            $this->notifications->mail(
                $booking,
                NotificationTemplate::QuoteSent,
                new GuestMail($booking, NotificationTemplate::QuoteSent, ['quote' => $quote]),
                once: false,
            );
        });
    }
}
