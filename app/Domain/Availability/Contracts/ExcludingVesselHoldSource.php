<?php

declare(strict_types=1);

namespace App\Domain\Availability\Contracts;

use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Availability\Support\Window;
use App\Models\Vessel;
use Illuminate\Support\Carbon;

/**
 * A hold source that can leave one booking out of its answer (2026-09-25).
 *
 * The question a charter asks when it is **confirmed** is not "is the boat
 * free" but "is the boat free of everybody else": its own draft, or its own
 * `pending_payment` row, occupies exactly the window it is asking about. AVL-9
 * gives a departure the same self-exclusion through
 * {@see OccupationCollector::isFree()}; this is the booking-shaped half of it.
 *
 * Separate from {@see VesselHoldSource} rather than an optional parameter on
 * it, so a source that cannot tell bookings apart still satisfies the port.
 * {@see OccupationCollector} falls back to the plain read for one of those.
 */
interface ExcludingVesselHoldSource extends VesselHoldSource
{
    /**
     * {@see VesselHoldSource::holdWindows()} without booking `$bookingId`.
     *
     * @return list<Window>
     */
    public function holdWindowsExcluding(Vessel $vessel, Window $range, Carbon $now, int $bookingId): array;
}
