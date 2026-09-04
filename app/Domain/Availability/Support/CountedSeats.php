<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Catalog\Support\AgeBandResolver;
use App\Models\AgeBand;
use Illuminate\Support\Collection;

/**
 * How many seats a party takes, and how many people it is (AVL-23, AVL-25).
 *
 * ## Two numbers, and conflating them is the bug this class prevents
 *
 * - **Counted seats** — pax in bands with `counts_toward_capacity = true`. This
 *   is what the commercial check compares against remaining seats.
 * - **Total persons** — everyone, infants included. This is what the **legal**
 *   check compares against the boat's certificate.
 *
 * A family of two adults and two infants on lap is two seats and four people.
 * Using the first number for the legal check lets a boat sail illegally full of
 * infants; using the second for the commercial check refuses a booking the
 * operator wanted to take. Spec AVL-25 calls the confusion out by name, which
 * is why they are two methods on one class rather than one number anywhere.
 *
 * A thin reader over {@see AgeBandResolver}, whose set-level rules already
 * exist — this exists so the availability path has the two figures named,
 * rather than two call sites each remembering which resolver method to use.
 */
final class CountedSeats
{
    /**
     * @param  iterable<AgeBand>  $bands
     * @param  array<string, int>  $paxByCode  band code => how many
     */
    public static function counted(iterable $bands, array $paxByCode): int
    {
        return AgeBandResolver::countedSeats($bands, $paxByCode);
    }

    /** @param array<string, int> $paxByCode */
    public static function totalPersons(array $paxByCode): int
    {
        return AgeBandResolver::totalPersons($paxByCode);
    }

    /**
     * AVL-26: a party with nobody in a counted band cannot board.
     *
     * Two infants alone is not a booking — an infant travels on a lap, and the
     * lap has to belong to somebody. This is refused with its own code rather
     * than reported as "no seats", because the guest's remedy is completely
     * different: add an adult, not pick another date.
     *
     * @param  iterable<AgeBand>  $bands
     * @param  array<string, int>  $paxByCode
     */
    public static function hasCountedPax(iterable $bands, array $paxByCode): bool
    {
        return self::counted($bands, $paxByCode) > 0;
    }

    /**
     * The party as the engine reads it, with negatives and unknown codes gone.
     *
     * A widget on somebody else's page can post anything. Filtering here rather
     * than trusting the caller means a negative infant count cannot reduce a
     * party's size, which is the shape of a free-seat exploit.
     *
     * @param  iterable<AgeBand>  $bands
     * @param  array<string, int>  $paxByCode
     * @return array<string, int>
     */
    public static function sanitise(iterable $bands, array $paxByCode): array
    {
        $codes = ($bands instanceof Collection ? $bands : collect($bands))
            ->pluck('code')
            ->all();

        $clean = [];

        foreach ($paxByCode as $code => $count) {
            if (in_array($code, $codes, strict: true)) {
                $clean[(string) $code] = max(0, (int) $count);
            }
        }

        return $clean;
    }
}
