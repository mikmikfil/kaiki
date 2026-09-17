<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Booking\Actions\GenerateETicket;
use App\Events\BookingGuestsRemoved;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * New e-tickets once people come off a booking (2026-09-17).
 *
 * The old PDF names passengers who are no longer travelling and a head-count
 * the boat will not see. Only a booking that already had a ticket gets a new
 * one: one that was never issued is not this listener's to start.
 */
class RegenerateETicketOnChange implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly GenerateETicket $tickets) {}

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

            if ($booking instanceof Booking && $booking->eticket_path !== null) {
                ($this->tickets)($booking);
            }
        });
    }
}
