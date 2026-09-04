<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Data\Pricing\ResolvedRatePlanData;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which rate plan prices a product on a date (spec PRC-3, PRC-4, PRC-5).
 *
 * ## It takes collections, never a query
 *
 * This is called **once per date** in a 62-day availability response, and again
 * for every product in a list. A resolver that queried per date would turn the
 * hottest read path in the product into an N+1 that only shows up under a real
 * calendar (NFR-6, NFR-7). So the caller loads the plans and the seasons once —
 * {@see self::load()} does it in three queries, whatever the range — and every
 * subsequent date is decided in PHP, at zero further cost.
 *
 * Every method is static and takes values. There is nothing to construct and
 * nothing to inject, which is also what makes the table-driven test possible.
 *
 * ## PRC-5's walk, in order
 *
 * 1. Seasons containing the date, ordered by {@see SeasonCandidateResolver} —
 *    priority descending, then narrowest matching range, then id.
 * 2. The **first** of those that has an active plan for this product wins. Not
 *    the first season: a season may be the highest-priority match and simply
 *    have no plan for this product, and PRC-5 says to keep walking rather than
 *    to fail there.
 * 3. Failing all of them, the product default plan — the one with `season_id`
 *    null.
 * 4. Failing that, **not sellable**. Never a zero.
 */
final class RatePlanResolver
{
    /**
     * Everything the resolver needs for a product, in three queries.
     *
     * Plans, seasons, and the seasons' date ranges. Three regardless of how
     * many dates the caller is about to ask about, which is the property that
     * matters — a sixty-day availability response costs the same as one day.
     *
     * Seasons are loaded whole rather than filtered by date, because the caller
     * is about to ask about sixty of them and the operator's whole calendar is
     * a handful of rows. Ranges come eager-loaded, since `contains()` reads them
     * for every date.
     *
     * @return array{plans: Collection<int, RatePlan>, seasons: Collection<int, Season>}
     */
    public static function load(Product $product): array
    {
        /** @var Collection<int, RatePlan> $plans */
        $plans = RatePlan::query()
            ->where('product_id', $product->getKey())
            ->active()
            ->get();

        /** @var Collection<int, Season> $seasons */
        $seasons = Season::query()
            ->active()
            ->with('dateRanges')
            ->get();

        return ['plans' => $plans, 'seasons' => $seasons];
    }

    /**
     * Resolve for one date against preloaded collections.
     *
     * @param  Collection<int, RatePlan>  $plans  active plans for **one** product
     * @param  Collection<int, Season>  $seasons  active seasons for the tenant
     */
    public static function resolve(Collection $plans, Collection $seasons, Carbon $date): ResolvedRatePlanData
    {
        $matching = $seasons->filter(fn (Season $season): bool => $season->contains($date));

        foreach (SeasonCandidateResolver::order($matching, $date) as $season) {
            $plan = $plans->first(
                fn (RatePlan $candidate): bool => $candidate->season_id === $season->getKey(),
            );

            if ($plan !== null) {
                return ResolvedRatePlanData::sellable($plan, $season);
            }
        }

        $default = $plans->first(fn (RatePlan $candidate): bool => $candidate->season_id === null);

        return $default !== null
            ? ResolvedRatePlanData::sellable($default, null)
            : ResolvedRatePlanData::notSellable();
    }

    /**
     * Resolve a whole range, at no query cost beyond the load.
     *
     * The shape the availability endpoint wants: date string => resolution. The
     * loop is deliberately over dates rather than over plans, because a date
     * with no plan has to appear in the result as "not sellable" — dropping it
     * would make the caller unable to tell "not sellable" from "not asked".
     *
     * @param  Collection<int, RatePlan>  $plans
     * @param  Collection<int, Season>  $seasons
     * @return array<string, ResolvedRatePlanData>
     */
    public static function resolveRange(Collection $plans, Collection $seasons, Carbon $from, Carbon $to): array
    {
        $resolved = [];

        for ($date = $from->copy()->startOfDay(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $resolved[$date->toDateString()] = self::resolve($plans, $seasons, $date);
        }

        return $resolved;
    }

    /** Convenience for a single lookup, where the two queries are the point. */
    public static function forProduct(Product $product, Carbon $date): ResolvedRatePlanData
    {
        ['plans' => $plans, 'seasons' => $seasons] = self::load($product);

        return self::resolve($plans, $seasons, $date);
    }
}
