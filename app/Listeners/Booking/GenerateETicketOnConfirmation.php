<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Booking\Actions\GenerateETicket;
use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * BKG-13.1: the e-ticket PDF with its QR codes.
 *
 * ## Queued and independently retryable, because BKG-14 says so
 *
 * *"A failure in any listener MUST NOT roll back the confirmation or block the
 * others."* This one launches a headless Chromium, which is by some distance
 * the heaviest of BKG-13's nine and the most likely to fail for reasons that
 * have nothing to do with the booking — a browser that will not start, a host
 * out of memory, a render that runs long. None of that may reach the guest's
 * confirmation email, and none of it may unwind a payment.
 *
 * Three attempts with a widening backoff, and then the failure feed. The
 * backoff is longer than the notification listener's because the failure this
 * retries is usually a busy host rather than a flaky API: retrying a memory
 * exhaustion after thirty seconds mostly reproduces it.
 *
 * ## Ids, not a model
 *
 * The lesson #53 paid for. A queued listener is constructed on a worker, where
 * a serialised model would be re-fetched inside whatever tenant the previous
 * job left behind — so the tenant is resolved first and the booking second.
 *
 * ## Not blocking, and the guest is not left without one
 *
 * BKG-13.2's email links the ticket rather than waiting for it, and
 * `/b/{manage_token}` regenerates on demand for the booking whose ticket never
 * got made. So a permanent failure here costs an operator a row in the failure
 * feed, not a guest at a quay with nothing to show.
 */
class GenerateETicketOnConfirmation implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(private readonly GenerateETicket $tickets) {}

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

            ($this->tickets)($booking);
        });
    }
}
