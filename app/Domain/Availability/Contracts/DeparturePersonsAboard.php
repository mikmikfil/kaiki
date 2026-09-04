<?php

declare(strict_types=1);

namespace App\Domain\Availability\Contracts;

use App\Models\Departure;

/**
 * How many people are already booked onto a departure, infants included
 * (spec AVL-25).
 *
 * ## Why an interface with no implementation
 *
 * AVL-25's legal check counts *"every person including age bands with
 * `counts_toward_capacity = false`, **across all bookings on that
 * departure**"*. `departures` carries `seats_sold`, which counts seats rather
 * than people, and there is no column for the total — the number lives in the
 * bookings, and `bookings` does not exist until M2.
 *
 * So the check ships now against this contract, with **no implementation
 * registered**: it correctly reports zero, because nothing can yet hold a
 * booking. M2 adds one class and one `tag()` line, and the rule, its error code
 * and its tests are already built.
 *
 * Exactly the shape #16 used for `GuardVesselCapacity` and #18 for
 * `ProductBookingCount`, and for the same reason: writing a legal-capacity
 * check under time pressure inside M2 is how a boat comes to sail full of
 * infants.
 */
interface DeparturePersonsAboard
{
    /** Every person already aboard, counted and non-counted alike. */
    public function personsAboard(Departure $departure): int;
}
