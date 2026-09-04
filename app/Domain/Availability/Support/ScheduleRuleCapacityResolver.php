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

    /** The legal ceiling, or null when no vessel is attached yet. */
    public static function vesselCeiling(ScheduleRule $rule): ?int
    {
        $vesselId = self::vesselId($rule);

        if ($vesselId === null) {
            return null;
        }

        $vessel = $rule->vessel_id !== null && $rule->relationLoaded('vessel')
            ? $rule->vessel
            : Vessel::query()->find($vesselId);

        return $vessel?->capacity_max;
    }
}
