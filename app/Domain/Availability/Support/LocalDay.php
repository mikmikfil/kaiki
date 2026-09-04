<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\LocalDateTimeResolver;
use Illuminate\Support\Carbon;

/**
 * A local calendar day as a half-open UTC interval (spec AVL-14, AVL-13).
 *
 * `[start_of_day(D), start_of_day(D + 1))` — and the reason it is a class
 * rather than `startOfDay()`/`endOfDay()` at a call site is that **a day is not
 * always 24 hours**. In Europe/Athens the last Sunday of March is 23 hours long
 * and the last Sunday of October is 25. Code that adds 86400 seconds to a day's
 * start is wrong twice a year, in opposite directions, on the two days most
 * likely to carry an odd departure.
 *
 * Half-open on purpose: `>= start && < end` composes across consecutive days
 * with no gap and no double count, which an inclusive end cannot do without
 * inventing a "last microsecond".
 *
 * Every comparison downstream is between UTC columns (AVL-13). Local dates are
 * for display and for the operator's mental model; they are never the thing an
 * interval test compares.
 */
final class LocalDay
{
    private function __construct(
        public readonly Carbon $startUtc,
        public readonly Carbon $endUtcExclusive,
        public readonly string $localDate,
        public readonly string $timezone,
    ) {}

    /** The interval covering local day `$date` in `$timezone`. */
    public static function of(Carbon|string $date, string $timezone): self
    {
        $localDate = $date instanceof Carbon ? $date->toDateString() : substr($date, 0, 10);

        // Midnight always exists in Europe/Athens — both transitions happen at
        // 03:00 and 04:00 — but resolving through the one authority keeps that
        // an observation rather than an assumption, and holds for a timezone
        // that moves its clock at midnight.
        $start = LocalDateTimeResolver::resolve($localDate, '00:00:00', $timezone)->instantOrFail();

        $next = Carbon::parse($localDate)->addDay()->toDateString();
        $end = LocalDateTimeResolver::resolve($next, '00:00:00', $timezone)->instantOrFail();

        return new self($start, $end, $localDate, $timezone);
    }

    /** Today, in the tenant's timezone rather than the server's. */
    public static function today(string $timezone): self
    {
        return self::of(Carbon::now($timezone)->toDateString(), $timezone);
    }

    /** How long this day actually is: 23, 24 or 25 hours. */
    public function hours(): int
    {
        return (int) round(($this->endUtcExclusive->getTimestamp() - $this->startUtc->getTimestamp()) / 3600);
    }

    /** Half-open: the first instant of the next day is not in this one. */
    public function contains(Carbon $instantUtc): bool
    {
        $instant = $instantUtc->copy()->utc();

        return $instant->greaterThanOrEqualTo($this->startUtc)
            && $instant->lessThan($this->endUtcExclusive);
    }

    /**
     * Does a window touch this day at all?
     *
     * Half-open on both sides, so a departure ending exactly at midnight
     * belongs to the day it started in and not to the next one.
     */
    public function overlaps(Carbon $startsAtUtc, Carbon $endsAtUtc): bool
    {
        return $startsAtUtc->copy()->utc()->lessThan($this->endUtcExclusive)
            && $endsAtUtc->copy()->utc()->greaterThan($this->startUtc);
    }
}
