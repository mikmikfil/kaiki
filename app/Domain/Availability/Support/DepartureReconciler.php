<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\ScheduleRule;
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
 */
final class DepartureReconciler
{
    public const DST_SKIPPED = 'dst_skipped';

    public const ORPHANED = 'orphaned';

    public const CAPACITY_DRIFT = 'capacity_drift';

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

        ScheduleRule::query()->active()->with('product')->get()
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
        $product = (string) $rule->product->title;

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

        Departure::query()
            ->where('schedule_rule_id', $rule->getKey())
            ->where('local_date', '>=', $today->toDateString())
            ->whereNot('status', DepartureStatus::Cancelled)
            ->get()
            ->each(function (Departure $departure) use (&$issues, $rule, $product, $expected, $capacity): void {
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
