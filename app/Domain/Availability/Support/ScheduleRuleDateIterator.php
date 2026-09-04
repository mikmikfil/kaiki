<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Models\ScheduleRule;
use Generator;
use Illuminate\Support\Carbon;

/**
 * Which local dates a rule should produce, inside the horizon
 * (ADR-0009, spec AVL-52, AVL-54).
 *
 * ## A generator, because the horizon is 400 days
 *
 * ADR-0009 Option A sets a rolling 400-day horizon. A daily rule over that
 * horizon is 400 dates, and a tenant with a dozen rules is five thousand — all
 * of which would otherwise be materialised as `Carbon` objects before the first
 * one is used. Yielding keeps the job's memory flat whatever the operator's
 * calendar looks like (NFR-10).
 *
 * ## The window is the intersection of four things
 *
 * The rule's `valid_from`, its `valid_until` (or the horizon, whichever comes
 * first), today (never the past — a departure yesterday is not something to
 * create), and the watermark. Getting any one of them wrong is a job that
 * either does nothing or backfills a season nobody asked for.
 *
 * ## The watermark is a resume point, not a filter
 *
 * `last_generated_on` exists so a failed partial run resumes rather than
 * restarting (§2.3). It is applied as a *floor* on the start date and never as
 * a reason to skip a date inside the window — because a rule that gains a
 * weekday must still fill in the past-the-watermark dates it now matches, and
 * generation is idempotent anyway.
 */
final class ScheduleRuleDateIterator
{
    /**
     * The local dates this rule should have departures on.
     *
     * @return Generator<int, Carbon>
     */
    public static function dates(ScheduleRule $rule, Carbon $today, int $horizonDays): Generator
    {
        if (! $rule->is_active) {
            return;
        }

        $start = self::startDate($rule, $today);
        $end = self::endDate($rule, $today, $horizonDays);

        if ($start->greaterThan($end)) {
            return;
        }

        for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            if (WeekdayMask::covers($rule->weekday_mask, $date)) {
                yield $date->copy();
            }
        }
    }

    /**
     * The first date to consider.
     *
     * Never before today: generation fills the future, and a job run after a
     * gap must not manufacture departures for dates that have already passed.
     */
    public static function startDate(ScheduleRule $rule, Carbon $today): Carbon
    {
        return self::plainDate($rule->valid_from)->max(self::plainDate($today));
    }

    /**
     * The last date to consider: the horizon, or the rule's own end.
     *
     * An open-ended rule (`valid_until` null) still stops at the horizon —
     * §2.3 is explicit that "open-ended" means the operator sets no end, not
     * that the job generates forever.
     */
    public static function endDate(ScheduleRule $rule, Carbon $today, int $horizonDays): Carbon
    {
        $horizon = self::plainDate($today)->addDays(max(0, $horizonDays));

        if ($rule->valid_until === null) {
            return $horizon;
        }

        return self::plainDate($rule->valid_until)->min($horizon);
    }

    /** A calendar date with no timezone attached — see the class docblock. */
    private static function plainDate(Carbon $date): Carbon
    {
        return Carbon::parse($date->toDateString());
    }
}
