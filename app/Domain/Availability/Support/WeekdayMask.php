<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use Illuminate\Support\Carbon;

/**
 * The weekday bitmask, and the one place its convention lives
 * (`docs/data-model.md` §2.3).
 *
 * **Monday is bit 0, Sunday is bit 6.** That is ISO-8601's week and the one a
 * Greek operator reads on a calendar. PHP disagrees with itself about this —
 * `date('w')` counts Sunday as 0, `date('N')` counts Monday as 1 — and a
 * conversion written at a call site is a conversion that is right in the
 * generator and off by one in the panel preview, which is a trip that runs on
 * the wrong day.
 *
 * So the arithmetic is here, every method is static, and nothing else in the
 * codebase writes `1 << $day`.
 */
final class WeekdayMask
{
    /** Every day of the week: `0b1111111`. */
    public const DAILY = 127;

    /** ISO weekday numbers, Monday first — the order the panel renders. */
    public const ISO_DAYS = [1, 2, 3, 4, 5, 6, 7];

    /** The bit for one ISO weekday (1 = Monday … 7 = Sunday). */
    public static function bit(int $isoWeekday): int
    {
        return 1 << (max(1, min(7, $isoWeekday)) - 1);
    }

    /** Does this mask include `$date`'s weekday? */
    public static function covers(int $mask, Carbon $date): bool
    {
        return self::includes($mask, (int) $date->isoWeekday());
    }

    public static function includes(int $mask, int $isoWeekday): bool
    {
        return ($mask & self::bit($isoWeekday)) !== 0;
    }

    /**
     * A mask from ISO weekday numbers.
     *
     * @param  iterable<int|string>  $isoWeekdays
     */
    public static function fromDays(iterable $isoWeekdays): int
    {
        $mask = 0;

        foreach ($isoWeekdays as $day) {
            $mask |= self::bit((int) $day);
        }

        return $mask;
    }

    /**
     * The ISO weekday numbers a mask covers, Monday first.
     *
     * @return list<int>
     */
    public static function toDays(int $mask): array
    {
        return array_values(array_filter(
            self::ISO_DAYS,
            static fn (int $day): bool => self::includes($mask, $day),
        ));
    }

    /** How many days a week this rule fires. Zero means never. */
    public static function count(int $mask): int
    {
        return count(self::toDays($mask));
    }

    /**
     * The next `$limit` dates on or after `$from` that this mask covers.
     *
     * Bounded by `$limit` rather than by a date, so an empty mask cannot spin —
     * the Action refuses one, and a helper that hangs on invalid input is a
     * helper that eventually meets some.
     *
     * @return list<Carbon>
     */
    public static function nextDates(int $mask, Carbon $from, int $limit, ?Carbon $until = null): array
    {
        if ($mask === 0 || $limit < 1) {
            return [];
        }

        $dates = [];
        $cursor = $from->copy()->startOfDay();

        // A week's worth of candidates per match is the theoretical worst case;
        // the cap is generous and finite either way.
        for ($step = 0; $step < $limit * 7 + 7 && count($dates) < $limit; $step++) {
            if ($until !== null && $cursor->greaterThan($until)) {
                break;
            }

            if (self::covers($mask, $cursor)) {
                $dates[] = $cursor->copy();
            }

            $cursor->addDay();
        }

        return $dates;
    }
}
