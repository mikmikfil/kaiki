<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Pricing\Support\RefundCalculator;
use App\Models\AgeBand;
use Illuminate\Support\Collection;

/**
 * Which band a passenger of a given age falls into (spec CAT-7, AVL-23, PRC-7).
 *
 * Small, and load-bearing out of proportion to its size: pricing asks it what
 * to charge, the availability engine asks it whether a passenger consumes a
 * seat, and the manifest asks it what to print beside a name. One answer, in
 * one place, so those three cannot disagree about a two-year-old.
 *
 * ## Pure, and given bands rather than a product
 *
 * It takes a collection, never a `Product` and never an id — so it can be run
 * against a **snapshot** of the bands taken at booking time as easily as
 * against the live ones. That matters for the same reason it matters in
 * {@see RefundCalculator}: an operator editing
 * their age bands must not change what a guest who already booked was charged,
 * and a resolver that could load the current bands would make that impossible
 * to guarantee.
 *
 * ## Exactly one band, or none
 *
 * CAT-8 forbids overlapping ranges, so a valid set can only ever match once.
 * This does not *rely* on that: it takes the narrowest match, so a set that
 * somehow slipped through — a row written around the Action, an import from an
 * older version — resolves deterministically rather than by row order. An age
 * covered by no band returns null, which is a real answer: a product sold to
 * adults only has nothing to charge a five-year-old, and the booking form must
 * say so rather than guess.
 */
final class AgeBandResolver
{
    /**
     * The band covering `$age`, or null when none does.
     *
     * @param  iterable<AgeBand>  $bands
     */
    public static function forAge(iterable $bands, int $age): ?AgeBand
    {
        $matches = self::collect($bands)->filter(fn (AgeBand $band): bool => $band->covers($age));

        if ($matches->isEmpty()) {
            return null;
        }

        // The narrowest wins. With a valid set there is only one; with an
        // invalid one this is still deterministic, and "0–2" beating "0–99" is
        // the answer a human would give.
        return $matches
            ->sortBy(fn (AgeBand $band): int => ($band->max_age ?? PHP_INT_MAX) - $band->min_age)
            ->first();
    }

    /**
     * The base band that multipliers anchor to (CAT-8).
     *
     * @param  iterable<AgeBand>  $bands
     */
    public static function base(iterable $bands): ?AgeBand
    {
        return self::collect($bands)->firstWhere('is_base', true);
    }

    /**
     * How many of these passengers consume a seat (AVL-23).
     *
     * The availability engine's question. An infant priced at zero and an
     * infant that consumes no seat are **different facts** — a band may not
     * count toward capacity and still be charged (PRC-7) — so this reads only
     * the flag and never the price.
     *
     * @param  iterable<AgeBand>  $bands
     * @param  array<string, int>  $paxByCode  band code => passenger count
     */
    public static function countedSeats(iterable $bands, array $paxByCode): int
    {
        $counted = 0;

        foreach (self::collect($bands) as $band) {
            if ($band->counts_toward_capacity) {
                $counted += max(0, $paxByCode[$band->code] ?? 0);
            }
        }

        return $counted;
    }

    /**
     * Everybody aboard, counted or not — the number the legal capacity check
     * and the manifest use.
     *
     * `capacity_max` is a certificate: an infant on a lap is a person on the
     * boat whether or not they occupy a seat, so this deliberately ignores
     * `counts_toward_capacity`.
     *
     * @param  array<string, int>  $paxByCode
     */
    public static function totalPersons(array $paxByCode): int
    {
        return array_sum(array_map(static fn (int $count): int => max(0, $count), $paxByCode));
    }

    /**
     * @param  iterable<AgeBand>  $bands
     * @return Collection<int, AgeBand>
     */
    private static function collect(iterable $bands): Collection
    {
        return $bands instanceof Collection ? $bands : collect($bands);
    }
}
