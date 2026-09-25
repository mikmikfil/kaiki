<?php

declare(strict_types=1);

namespace App\Domain\Availability\Contracts;

use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Availability\Support\Window;
use Illuminate\Support\Carbon;

/**
 * {@see VesselHoldSource}, asked about a whole fleet in one go (2026-09-25).
 *
 * The departures calendar reads every boat an operator sails for fourteen days
 * at once, and asking the per-vessel question six times would be six queries
 * for an answer one query holds. A source that can answer in bulk implements
 * this as well; one that cannot is asked boat by boat, exactly as before —
 * {@see OccupationCollector::forVessels()} checks
 * which it is, so nothing that implements only the narrow contract breaks.
 */
interface BulkVesselHoldSource extends VesselHoldSource
{
    /**
     * Unexpired private-hold windows overlapping `$range`, per vessel id.
     *
     * @param  list<int>  $vesselIds
     * @return array<int, list<Window>>
     */
    public function holdWindowsByVessel(array $vesselIds, Window $range, Carbon $now): array;
}
