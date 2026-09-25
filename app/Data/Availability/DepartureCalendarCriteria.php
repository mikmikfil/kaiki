<?php

declare(strict_types=1);

namespace App\Data\Availability;

use App\Domain\Availability\Support\DepartureCalendar;

/**
 * What a guest asked the departures calendar (2026-09-25).
 *
 * Already read and bounded by the caller — the hosted page from its query
 * string — so {@see DepartureCalendar} never has to decide what a malformed
 * `pax` means.
 */
final class DepartureCalendarCriteria
{
    public const PART_MORNING = 'morning';

    public const PART_AFTERNOON = 'afternoon';

    public const PART_EVENING = 'evening';

    /** @var list<string> */
    public const PARTS = [self::PART_MORNING, self::PART_AFTERNOON, self::PART_EVENING];

    /**
     * @param  string  $from  the first local day, `Y-m-d`
     * @param  list<string>  $tripSlugs  empty for every trip
     */
    public function __construct(
        public readonly string $from,
        public readonly int $days = DepartureCalendar::DAYS,
        public readonly int $pax = 2,
        public readonly array $tripSlugs = [],
        public readonly ?string $part = null,
        public readonly bool $onlyFree = false,
    ) {}

    /** Is anything narrowing the answer beyond the dates and the party? */
    public function isFiltered(): bool
    {
        return $this->tripSlugs !== [] || $this->part !== null || $this->onlyFree;
    }

    /**
     * Does a sailing at this local time belong to the chosen part of the day?
     *
     * Morning is before 12:00, afternoon 12:00 to 18:00, evening from 18:00 —
     * the three words a guest uses, and the three the filter offers.
     */
    public function matchesPart(string $localTime): bool
    {
        if ($this->part === null) {
            return true;
        }

        $hhmm = substr($localTime, 0, 5);

        return match ($this->part) {
            self::PART_MORNING => $hhmm < '12:00',
            self::PART_AFTERNOON => $hhmm >= '12:00' && $hhmm < '18:00',
            self::PART_EVENING => $hhmm >= '18:00',
            default => true,
        };
    }
}
