<?php

declare(strict_types=1);

namespace App\Listeners\Webhooks;

use App\Domain\Webhooks\Actions\DispatchWebhookEvent;
use App\Domain\Webhooks\Support\WebhookPayload;
use App\Enums\BookingSource;
use App\Enums\WebhookEvent;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\DepartureCancelled;
use App\Events\GuestDetailsCompleted;
use App\Jobs\DeliverWebhook;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;

/**
 * Four domain events become four webhook events (spec OPS-19).
 *
 * ## One listener, not four
 *
 * The other listeners in this application are one per concern —
 * `SendBookingConfirmation`, `GenerateETicketOnConfirmation` — because each does
 * a genuinely different thing with a different failure mode. These four do the
 * *same* thing four times: resolve the tenant, build a payload, hand it to
 * {@see DispatchWebhookEvent}. Four classes would be four copies of the same
 * six lines, and the rule about which events are published would live in four
 * places instead of one.
 *
 * ## Not queued, deliberately — and it is the only listener here that is not
 *
 * Its whole job is local: resolve, build, write a row per endpoint, dispatch a
 * job. The HTTP call — the part that can hang for ten seconds on somebody
 * else's server — is {@see DeliverWebhook}'s, one job per endpoint,
 * so one dead receiver cannot delay another operator's live one.
 *
 * Queueing this would buy nothing and cost correctness. {@see DepartureCancelled}
 * carries a **model** rather than ids, unlike every other event in that
 * directory, and a queued listener is constructed on a worker where a
 * serialised model is re-fetched under whatever tenant the previous job left
 * behind. That is the cross-tenant read #53 paid for learning about, and the
 * cheapest way not to have it is to run before the request ends.
 *
 * BKG-14 still holds: {@see DispatchWebhookEvent} swallows and reports its own
 * failures, so a webhook that cannot be queued is a logged problem rather than
 * a confirmation that rolled back.
 *
 * ## The suppressions live in the Action, not here
 *
 * BKG-34's imported bookings and SAA-12's test flag are decided by
 * `DispatchWebhookEvent::forBooking()`, so a fifth caller added later inherits
 * both rules instead of having to remember them.
 */
class PublishDomainEvent
{
    public function __construct(private readonly DispatchWebhookEvent $dispatch) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof BookingConfirmed => $this->booking($event->bookingId, WebhookEvent::BookingConfirmed),
            $event instanceof BookingCancelled => $this->booking($event->bookingId, WebhookEvent::BookingCancelled),
            $event instanceof GuestDetailsCompleted => $this->guestDetails($event),
            $event instanceof DepartureCancelled => $this->departure($event),
            default => null,
        };
    }

    private function booking(int $bookingId, WebhookEvent $event): void
    {
        $booking = $this->find($bookingId);

        if ($booking instanceof Booking) {
            ($this->dispatch)->forBooking($booking, $event);
        }
    }

    private function guestDetails(GuestDetailsCompleted $event): void
    {
        $booking = $this->find($event->bookingId);

        if (! $booking instanceof Booking || $booking->source === BookingSource::Import) {
            return;
        }

        $tenant = $this->tenant($booking->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        // Inside the tenant: the payload is the API's own booking resource and
        // that reads tenant-owned relations. `DispatchWebhookEvent::forBooking()`
        // learnt the same lesson; this branch does not go through it, because a
        // guest-details payload carries a count the booking does not know.
        $data = Tenancy::forTenant(
            $tenant,
            static fn (): array => WebhookPayload::guestDetails($booking, $event->guestCount),
        );

        ($this->dispatch)(
            $tenant,
            WebhookEvent::GuestDetailsCompleted,
            $data,
            (bool) $booking->is_test,
        );
    }

    private function departure(DepartureCancelled $event): void
    {
        $departure = $event->departure();

        $tenant = $this->tenant($departure->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        // No `is_test` on a departure: a sailing is not a test booking, and the
        // flag on §8.2's envelope describes the *thing that happened*. A test
        // booking on a cancelled departure gets its own `booking.cancelled`
        // carrying its own flag.
        Tenancy::forTenant($tenant, function () use ($tenant, $departure): void {
            ($this->dispatch)(
                $tenant,
                WebhookEvent::DepartureCancelled,
                WebhookPayload::departure($departure->loadMissing(['product', 'vessel'])),
                false,
            );
        });
    }

    /** Resolved outside tenancy, because a worker inherits whatever the last job left. */
    private function find(int $bookingId): ?Booking
    {
        return Tenancy::withoutTenancy(
            static fn (): ?Booking => Booking::query()->withoutGlobalScopes()->find($bookingId),
        );
    }

    private function tenant(int $tenantId): ?Tenant
    {
        return Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($tenantId),
        );
    }
}
