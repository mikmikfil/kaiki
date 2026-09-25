<?php

declare(strict_types=1);

namespace App\Domain\Availability\Contracts;

use App\Models\Departure;

/**
 * {@see DeparturePersonsAboard} for a whole range at once (NFR-7; 2026-09-25).
 *
 * The calendar asks the legal question of every sailing that has room for the
 * party, and one query per sailing would turn a five-query read into sixty.
 * A source that can answer in one grouped query implements this too.
 */
interface BulkDeparturePersonsAboard extends DeparturePersonsAboard
{
    /**
     * Everyone aboard each of these departures, keyed by departure id. A
     * departure missing from the answer has nobody aboard.
     *
     * @param  iterable<Departure>  $departures
     * @return array<int, int>
     */
    public function personsAboardMany(iterable $departures): array;
}
