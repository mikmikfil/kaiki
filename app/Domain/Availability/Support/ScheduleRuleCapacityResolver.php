<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Models\ScheduleRule;
use App\Models\Vessel;

/**
 * How many seats a generated departure gets (spec AVL-55,
 * `docs/data-model.md` §1.9).
 *
 * `capacity_override ?? products.max_pax`, **capped at the vessel's
 * `capacity_max`**. The cap is the part that matters: a boat's passenger
 * certificate is a legal document, and a rule that oversells it is discovered
 * by the port authority rather than by a test.
 *
 * One documented resolver because §1.9 snapshots this figure onto
 * `departures.capacity` at generation time, and the panel preview shows the
 * same number before the operator saves. Two implementations would eventually
 * disagree, and the operator would be told one figure and sold another.
 *
 * The vessel is the rule's own override where it has one, and the product's
 * otherwise — resolved here rather than by the caller, since "which boat" and
 * "how many seats" are the same question asked twice.
 */
final class ScheduleRuleCapacityResolver
{
    public static function capacity(ScheduleRule $rule): int
    {
        // `?->` on the left of `??` is redundant — `??` already suppresses the
        // null access — and PHPStan says so.
        $requested = $rule->capacity_override ?? $rule->product->max_pax ?? 0;
        $ceiling = self::vesselCeiling($rule);

        $capacity = $ceiling === null ? $requested : min($requested, $ceiling);

        return max(0, $capacity);
    }

    /** The boat this rule actually sails, override first (§2.3). */
    public static function vesselId(ScheduleRule $rule): ?int
    {
        return $rule->vessel_id ?? $rule->product->vessel_id;
    }

    /**
     * The legal ceiling, or null when no vessel is attached yet.
     *
     * Read through the **relations**, never an ad-hoc `find()`. Eloquent caches
     * a loaded relation and does not cache a query, and this method is called
     * once per generated departure — four hundred of them for a daily rule over
     * the horizon. A `find()` here was exactly that N+1, and it looked like
     * nothing.
     */
    public static function vesselCeiling(ScheduleRule $rule): ?int
    {
        $vessel = $rule->vessel_id !== null ? $rule->vessel : $rule->product->vessel;

        return $vessel?->capacity_max;
    }
}
