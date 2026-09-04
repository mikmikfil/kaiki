<?php

declare(strict_types=1);

namespace App\Domain\Availability\Contracts;

use App\Domain\Availability\Support\Window;
use App\Models\Vessel;
use Illuminate\Support\Carbon;

/**
 * Windows a guest is currently holding on a boat (spec AVL-3.4, AVL-33).
 *
 * ## The fourth occupation source, and the only one that expires
 *
 * AVL-3 lists four sources. Three are rows that sit there — a block, a
 * departure with seats, a confirmed private booking's block. The fourth is *"a
 * `Booking` in mode `per_vessel` in status `draft` with an unexpired
 * `hold_expires_at`"*, and it is different in kind: it occupies the boat for
 * twenty minutes and then stops, with nothing written and nothing deleted.
 *
 * AVL-33 follows from that: while a private hold is active, conflicting
 * zero-sold departures are **hidden from availability but not cancelled**, and
 * *"if the hold expires they become available again."* A stored flag could not
 * express that — it would have to be unset by something watching a clock — so
 * the question is asked at read time, every time.
 *
 * ## No implementation until M2
 *
 * `bookings` does not exist yet, so nothing is registered and this correctly
 * reports no holds. The rule, the hiding behaviour and their tests ship now;
 * M2 adds one class and one `tag()` line. The same shape as
 * {@see DeparturePersonsAboard}, and for the same reason — a hold rule written
 * under time pressure inside the booking transaction is how a boat gets sold
 * twice.
 */
interface VesselHoldSource
{
    /**
     * Unexpired private-hold windows for `$vessel` overlapping `$range`.
     *
     * @return list<Window>
     */
    public function holdWindows(Vessel $vessel, Window $range, Carbon $now): array;
}
