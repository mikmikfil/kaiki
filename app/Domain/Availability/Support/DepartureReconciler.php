<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Vessel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where a rule and its departures have drifted apart (ADR-0009, ADR-0016).
 *
 * ## Computed on read, with no table behind it
 *
 * ADR-0016 sketches a `schedule_rule_issues` table. This does the same job by
 * deriving the answer from the rule and its departures, and the reason is that
 * an issues table is itself a thing that goes stale: an operator who fixes a
 * rule would leave rows behind claiming a problem that no longer exists, and
 * the list would need reconciling of its own. A derived answer cannot be wrong
 * about the present.
 *
 * It is cheap because the horizon is bounded: 400 dates per rule walked in PHP,
 * and one query for the rule's future departures.
 *
 * ## Three kinds of divergence, and each is the operator's decision
 *
 * - **`dst_skipped`** — the rule matches the date but the local time does not
 *   exist there (spring forward). ADR-0016 refuses to invent one; the operator
 *   adds a manual departure at a real time if they want one.
 * - **`orphaned`** — a future departure whose rule no longer matches its date,
 *   because the rule was edited. Generation is additive only, so it is still
 *   standing and still sellable. Cancelling it might strand a booking, so
 *   nothing here does.
 * - **`capacity_drift`** — a sold departure whose capacity no longer matches
 *   the rule. Lowering it is how a guest loses a seat they paid for, so it is
 *   listed rather than applied.
 * - **`vessel_drift`** — a future departure left on another boat after the
 *   rule's boat changed (audit 2): it has bookings, or the new boat was taken
 *   at that hour. Moving it, or cancelling it, is the operator's call.
 */
final class DepartureReconciler
{
    public const DST_SKIPPED = 'dst_skipped';

    public const ORPHANED = 'orphaned';

    public const CAPACITY_DRIFT = 'capacity_drift';

    public const VESSEL_DRIFT = 'vessel_drift';

    /**
     * Everything an operator should look at, across every active rule.
     *
     * @return Collection<int, array{kind: string, rule_id: int, product: string, local_date: string, detail: string}>
     */
    public static function all(?Carbon $today = null): Collection
    {
        $today ??= Carbon::now(LocalDateTimeResolver::timezone());

        /** @var Collection<int, array{kind: string, rule_id: int, product: string, local_date: string, detail: string}> $issues */
        $issues = collect();

        /*
         * **A rule whose trip is gone is skipped, not read** (2026-09-22).
         *
         * Deleting a trip is a soft delete and its schedule rules stay behind,
         * so `$rule->product` is null — and `$rule->product->title` below took
         * down **every page of the panel** for that operator, not merely this
         * list: the reconciler runs inside a widget the layout renders. One
         * archived trip, and the operator cannot open their own dashboard.
         *
         * Skipped rather than reported, because there is nothing for an
         * operator to decide here: the trip is not on sale, its departures
         * cannot be booked, and the rule matters again only if the trip comes
         * back. Restoring the trip brings its rules back into this list with
         * it.
         */
        ScheduleRule::query()->active()->whereHas('product')->with('product')->get()
            ->each(function (ScheduleRule $rule) use ($issues, $today): void {
                $issues->push(...self::forRule($rule, $today));
            });

        return $issues->values();
    }

    /**
     * @return list<array{kind: string, rule_id: int, product: string, local_date: string, detail: string}>
     */
    public static function forRule(ScheduleRule $rule, Carbon $today): array
    {
        $timezone = LocalDateTimeResolver::timezone();
        $horizon = (int) config('kaiki.departures.horizon_days', 400);
        /*
         * Through `getRelationValue()`, because this method is public and a
         * caller may hand it a rule whose trip was soft-deleted — in which case
         * the relation is null however the model annotates it. Reading the
         * property directly is what took the panel down, and static analysis
         * believes the relation can never be null, so the check has to be made
         * on a value the analyser cannot narrow.
         */
        $related = $rule->getRelationValue('product');
        $product = $related instanceof Product ? (string) $related->title : '';

        $issues = [];
        $expected = [];

        foreach (ScheduleRuleDateIterator::dates($rule, $today, $horizon) as $date) {
            $day = $date->toDateString();
            $expected[$day] = true;

            if (! LocalDateTimeResolver::resolve($date, $rule->start_time, $timezone)->existent) {
                $issues[] = [
                    'kind' => self::DST_SKIPPED,
                    'rule_id' => (int) $rule->getKey(),
                    'product' => $product,
                    'local_date' => $day,
                    'detail' => (string) $rule->start_time,
                ];
            }
        }

        $capacity = $rule->effectiveCapacity();
        $vesselId = $rule->effectiveVesselId();
        $vesselName = $vesselId === null ? '' : (string) Vessel::query()->whereKey($vesselId)->value('name');

        Departure::query()
            ->where('schedule_rule_id', $rule->getKey())
            ->where('local_date', '>=', $today->toDateString())
            ->whereNot('status', DepartureStatus::Cancelled)
            ->with('vessel')
            ->get()
            ->each(function (Departure $departure) use (&$issues, $rule, $product, $expected, $capacity, $vesselId, $vesselName): void {
                $day = $departure->local_date->toDateString();

                if (! isset($expected[$day])) {
                    $issues[] = [
                        'kind' => self::ORPHANED,
                        'rule_id' => (int) $rule->getKey(),
                        'product' => $product,
                        'local_date' => $day,
                        'detail' => (string) $departure->seats_sold,
                    ];

                    return;
                }

                if ($vesselId !== null && (int) $departure->vessel_id !== $vesselId && $departure->status->isSellable()) {
                    $issues[] = [
                        'kind' => self::VESSEL_DRIFT,
                        'rule_id' => (int) $rule->getKey(),
                        'product' => $product,
                        'local_date' => $day,
                        'detail' => ($departure->vessel->name ?? '') . ' → ' . $vesselName,
                    ];

                    return;
                }

                // Unsold departures are brought into line by the generator, so
                // a drift that survives is one with seats on it.
                if ($departure->capacity !== $capacity && $departure->seats_sold > 0) {
                    $issues[] = [
                        'kind' => self::CAPACITY_DRIFT,
                        'rule_id' => (int) $rule->getKey(),
                        'product' => $product,
                        'local_date' => $day,
                        'detail' => "{$departure->capacity} → {$capacity}",
                    ];
                }
            });

        return $issues;
    }
}
