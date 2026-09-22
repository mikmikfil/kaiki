<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\VesselCalendar;
use App\Domain\Catalog\Actions\SaveScheduleRule;
use App\Models\Departure;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Vessel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Other trips the boat is already committed to when this rule sails
 * (product owner, 2026-09-22: *«υπάρχει έλεγχος αν φτιάξω νέα εκδρομή και το
 * καράβι έχει ήδη δεσμευτεί σε άλλη εκδρομή για τις ημέρες/ώρες που θα
 * βάλω;»*).
 *
 * There was not. A **manual** departure has been checked since M2 — see
 * {@see DepartureConflictFinder} — but a schedule rule never looked at the
 * boat's calendar at all, and a schedule rule is how a trip gets its days. So
 * a second trip on the same boat, Tuesdays at ten, was created in silence.
 *
 * ## A warning, and deliberately not a refusal
 *
 * AVL-11 is explicit that overlapping zero-sold departures may coexist:
 * operators put two products on one boat at the same hour and let the bookings
 * decide which one sails. Refusing that would be wrong, and would be a new rule
 * invented by a form. What was missing is only that nobody was **told**.
 *
 * ## Six occurrences, not four hundred
 *
 * A daily rule covers the whole horizon, and asking the calendar four hundred
 * times on a form submit is a form that hangs. A recurring clash shows up in
 * the first occurrences by definition — if the boat is busy every Tuesday at
 * ten, the first Tuesday says so — and a clash that only begins in four months
 * is one the operator will meet in the calendar long before they sail it.
 *
 * ## Through {@see VesselCalendar}, never a query of its own
 *
 * AVL-12a: that class is the only one permitted to ask what occupies a boat,
 * and it is where the turnaround buffer is applied — counted once, not once per
 * side. A check written here would eventually disagree with the generator about
 * whether a boat is free, which is the failure the rule exists to prevent.
 */
final class ScheduleRuleConflictFinder
{
    /** How many of the rule's own occurrences are sampled. */
    public const SAMPLES = 6;

    /**
     * Departures of **other** trips this rule would sail into.
     *
     * Its own are excluded: by the time this is asked the rule has usually
     * generated them, and a rule does not conflict with itself (AVL-9). Two
     * rules of the *same* trip at the same hour are refused outright by
     * {@see SaveScheduleRule}, so they never reach
     * here.
     *
     * @return Collection<int, Departure>
     */
    public static function forRule(ScheduleRule $rule, ?Carbon $today = null, int $samples = self::SAMPLES): Collection
    {
        /** @var Collection<int, Departure> $found */
        $found = collect();

        $product = $rule->getRelationValue('product');

        if (! $product instanceof Product) {
            return $found;
        }

        $vesselId = $rule->effectiveVesselId();
        $vessel = $vesselId === null ? null : Vessel::query()->find($vesselId);

        if (! $vessel instanceof Vessel) {
            return $found;
        }

        $today ??= Carbon::now(LocalDateTimeResolver::timezone());
        $horizon = (int) config('kaiki.departures.horizon_days', 400);
        $seen = 0;

        foreach (ScheduleRuleDateIterator::dates($rule, $today, $horizon) as $date) {
            if ($seen >= $samples) {
                break;
            }

            $seen++;

            $window = DepartureConflictFinder::windowFor($product, $date, (string) $rule->start_time);

            // The spring-forward gap: nothing can be created there, so there is
            // nothing for it to clash with.
            if ($window === null) {
                continue;
            }

            foreach (VesselCalendar::conflictingDepartures($vessel, $window) as $departure) {
                if ((int) $departure->product_id === (int) $product->getKey()) {
                    continue;
                }

                // Keyed, so the same departure met on two sampled dates — a
                // long charter spanning them — is named once.
                $found->put((int) $departure->getKey(), $departure);
            }
        }

        return $found->values();
    }
}
