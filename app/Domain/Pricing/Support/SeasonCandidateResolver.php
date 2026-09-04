<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Domain\Catalog\Actions\SaveSeason;
use App\Models\Season;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which seasons apply to a date, in the order pricing must consider them
 * (spec PRC-3, PRC-4).
 *
 * ## PRC-4 is defence in depth, not the primary rule
 *
 * A priority tie — two seasons, same priority, both containing the date — is
 * **prevented at save time** by {@see SaveSeason}.
 * That is the real protection, because a tie is a configuration mistake an
 * operator can see and fix, and resolving it silently at read time means their
 * prices are decided by a row id they never look at.
 *
 * This ordering exists anyway, for the rows that got in another way: an import,
 * a row written before the validation existed, a direct database edit. PRC-4:
 * *"The engine must never depend on database row order."*
 *
 * ## The order, and why the middle step is there
 *
 * 1. **`priority` descending** — higher wins, which is how "August" sits inside
 *    "Summer".
 * 2. **Narrowest matching range ascending** — a season whose two-week August
 *    range matches beats one whose whole-summer range also matches. The
 *    narrower statement is the more specific one, which is what an operator
 *    means by writing it. This step is in PRC-4 and missing from
 *    `docs/data-model.md`'s note, which is amended in this PR.
 * 3. **`id` ascending** — the last resort, and deterministic.
 *
 * ## One query, whatever the number of seasons
 *
 * NFR-6: the candidates are fetched with their ranges eager-loaded in a single
 * round trip, and every subsequent comparison is in PHP. A resolver that asked
 * the database per season would make price quoting scale with the size of the
 * operator's calendar.
 */
final class SeasonCandidateResolver
{
    /**
     * Every season containing `$date`, best first.
     *
     * @return Collection<int, Season>
     */
    public static function candidates(Carbon $date): Collection
    {
        $day = $date->toDateString();

        /** @var Collection<int, Season> $seasons */
        $seasons = Season::query()
            ->active()
            // The index is `(tenant_id, starts_on, ends_on)` and the global
            // scope supplies the tenant, so this is one indexed range scan.
            ->whereHas('dateRanges', function ($query) use ($day): void {
                $query->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day);
            })
            ->with('dateRanges')
            ->get();

        return self::order($seasons, $date);
    }

    /**
     * The winning season, or null when the date is in none.
     *
     * Null is a real answer: a tenant with no season covering a date prices
     * from the product's default rate plan, which is what `season_id` being
     * nullable on `rate_plans` means.
     */
    public static function resolve(Carbon $date): ?Season
    {
        return self::candidates($date)->first();
    }

    /**
     * PRC-4's ordering, applied to a set already known to match.
     *
     * Public and separate from the query so that it can be tested against
     * constructed seasons — and so that a caller holding candidates from
     * somewhere else orders them the same way.
     *
     * @param  Collection<int, Season>  $seasons
     * @return Collection<int, Season>
     */
    public static function order(Collection $seasons, Carbon $date): Collection
    {
        return $seasons
            ->sort(function (Season $a, Season $b) use ($date): int {
                // 1. Higher priority first.
                if ($a->priority !== $b->priority) {
                    return $b->priority <=> $a->priority;
                }

                // 2. Narrowest matching range first. PHP_INT_MAX for a season
                // with no matching range keeps a non-matching row last rather
                // than throwing — this method is also reachable with a set the
                // caller assembled.
                $aWidth = $a->narrowestMatchingRangeDays($date) ?? PHP_INT_MAX;
                $bWidth = $b->narrowestMatchingRangeDays($date) ?? PHP_INT_MAX;

                if ($aWidth !== $bWidth) {
                    return $aWidth <=> $bWidth;
                }

                // 3. The last resort, and the reason this is deterministic.
                return $a->getKey() <=> $b->getKey();
            })
            ->values();
    }

    /**
     * Seasons that would tie with `$season` on `$date` — the query
     * {@see SaveSeason} refuses a save on.
     *
     * @param  Collection<int, Season>  $candidates
     * @return Collection<int, Season>
     */
    public static function tiedWith(Collection $candidates, Season $season): Collection
    {
        return $candidates
            ->filter(fn (Season $other): bool => $other->getKey() !== $season->getKey()
                && $other->priority === $season->priority)
            ->values();
    }
}
