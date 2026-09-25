<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\Actions\HoldSeats;
use App\Exceptions\HoldRefused;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Vessel;

/**
 * The questions {@see HoldSeats} asks before it lets a party onto a sailing,
 * for the doors that take seats **without** a live hold (2026-09-25, audit 2):
 * a payment that lands after the checkout lapsed, a draft whose hold ran out on
 * the checkout page, an operator settling a draft whose hold is gone.
 *
 * Until then those doors compared seats alone, so a lapsed booking could be
 * revived onto a boat chartered, blocked or already full of infants since its
 * hold was released. The caller holds the vessel and departure row locks.
 */
final class SeatAdmission
{
    public function __construct(private readonly PartyGuard $party) {}

    /**
     * @throws HoldRefused departure_unavailable when the boat is taken or the
     *                     sailing blocked; legal_capacity when the certificate
     *                     would be exceeded (AVL-25)
     */
    public function refuseUnlessAdmissible(?Vessel $vessel, Departure $departure, Booking $booking): void
    {
        if ($departure->is_blocked || ($vessel instanceof Vessel && ! HoldSeats::vesselIsFreeFor($vessel, $departure))) {
            throw HoldRefused::departureUnavailable();
        }

        // The booking's own people, when they are already in the aboard figure
        // (a live hold or seats already committed), are not counted twice.
        $alreadyCounted = (int) $booking->departure_id === (int) $departure->getKey()
            && ($booking->holdsSeats() || $booking->status->committingSeats())
                ? $booking->pax_total
                : 0;

        $ceiling = $vessel instanceof Vessel ? $vessel->capacity_max : $departure->vessel?->capacity_max;

        if ($this->party->exceedsCertificate($ceiling, $booking->pax_total, $departure, $alreadyCounted)) {
            throw HoldRefused::legalCapacityExceeded();
        }
    }
}
