<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Departure;
use RuntimeException;

/**
 * A departure whose local time and UTC instant disagree (CNV-3).
 *
 * An exception rather than a validation error because no operator can cause it
 * from a form: the three columns are written together by the generator, and a
 * mismatch means code wrote them separately. The failure it prevents is a trip
 * that shows 09:00 on the ticket, 08:00 in the iCal feed and something else
 * again on the manifest.
 */
final class InconsistentDepartureTime extends RuntimeException
{
    public static function forDeparture(Departure $departure, string $expectedLocal): self
    {
        return new self(sprintf(
            'Departure %s stores local %s %s but its UTC instant renders as %s in %s. '
            . 'The three columns must always agree (CNV-3); write them through LocalDateTimeResolver.',
            (string) ($departure->uuid ?? 'new'),
            (string) $departure->local_date?->toDateString(),
            (string) $departure->local_time,
            $expectedLocal,
            $departure->timezone(),
        ));
    }
}
