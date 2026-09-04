<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Data\Availability\GenerationResultData;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\ScheduleRuleDateIterator;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\ScheduleRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create the departures a rule implies (ADR-0009 Option A, spec AVL-52 to
 * AVL-55).
 *
 * ## Additive only, and that is the whole safety argument
 *
 * ADR-0009: *"Editing a rule never silently mutates existing departures."* This
 * Action creates rows and does nothing else — it never updates a departure and
 * never deletes one. A rule whose capacity changed leaves its existing
 * departures exactly as they were, and the divergence surfaces in the
 * reconciliation list for the operator to act on.
 *
 * That makes the job safe to re-run, which is the property that matters most
 * for something on a nightly schedule. It also means a rule edit can never
 * cancel a departure somebody paid for, which is the failure the ADR was
 * written to prevent.
 *
 * ## Idempotency rests on the database, not on a second rule here
 *
 * `departures_tenant_prod_start_uq` is on `(tenant_id, product_id,
 * starts_at_utc)`. This Action reads the instants that already exist in the
 * window and skips them — an optimisation, not the guarantee. The guarantee is
 * the index, and it is on the UTC instant precisely so the October DST repeat
 * cannot collide. Adding a second uniqueness rule in PHP would be a second
 * answer to a question the schema already settles.
 *
 * ## Two queries per rule, whatever the horizon
 *
 * One select of the existing instants in the window, one bulk insert (NFR-6).
 * A per-date `firstOrCreate` would be 400 round trips for a daily rule, which
 * is the shape that makes a nightly job time out at the end of a season rather
 * than at the start.
 */
final class GenerateDepartures
{
    public function __invoke(ScheduleRule $rule, ?Carbon $today = null): GenerationResultData
    {
        $product = $rule->product;
        $vesselId = $rule->effectiveVesselId();

        // A rule with no boat cannot produce a departure: `departures.vessel_id`
        // is NOT NULL because a sailing without a vessel is not a sailing. The
        // reconciliation list is where the operator hears about it.
        if ($product === null || $vesselId === null) {
            return new GenerationResultData;
        }

        $timezone = LocalDateTimeResolver::timezone();
        $today ??= Carbon::now($timezone);
        $horizon = (int) config('kaiki.departures.horizon_days', 400);

        $existing = $this->existingInstants($rule, $today, $horizon);

        // Hoisted: the same for every date of this rule, and resolving it per
        // row would be a query per departure.
        $capacity = $rule->effectiveCapacity();
        $minPax = (int) $rule->product->min_pax;
        $duration = (int) $rule->product->duration_minutes;

        $rows = [];
        $skipped = 0;
        $dstSkipped = [];
        $lastDate = null;

        foreach (ScheduleRuleDateIterator::dates($rule, $today, $horizon) as $date) {
            $lastDate = $date;

            $resolved = LocalDateTimeResolver::resolve($date, $rule->start_time, $timezone);

            // ADR-0016 Option A: the spring-forward gap. Never invent a
            // departure at a time the operator did not choose; record it so the
            // panel can offer them a manual one at a real time.
            if (! $resolved->existent) {
                $dstSkipped[] = $date->toDateString();

                continue;
            }

            $starts = $resolved->instantOrFail();
            $key = $starts->toDateTimeString();

            if (isset($existing[$key])) {
                $skipped++;

                continue;
            }

            $rows[] = $this->row($rule, $product->getKey(), $vesselId, $starts, $resolved->ambiguous, $timezone, $capacity, $minPax, $duration);
            $existing[$key] = true;
        }

        if ($rows !== []) {
            // Chunked so a 400-day backfill is not one enormous statement.
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('departures')->insert($chunk);
            }
        }

        $this->syncUnsoldCapacity($rule, $today);

        // Advanced only to the last date actually walked, so a run that failed
        // part way resumes from where it stopped rather than from the start.
        if ($lastDate !== null) {
            $rule->forceFill(['last_generated_on' => $lastDate->toDateString()])->saveQuietly();
        }

        return new GenerationResultData(count($rows), $skipped, $dstSkipped);
    }

    /**
     * Apply a changed capacity to future departures nobody has booked.
     *
     * ADR-0009 draws the line here: *"Capacity override changes apply to future
     * departures with `seats_sold = 0`; departures with sales keep their
     * captured capacity and are listed for manual review."*
     *
     * The `where` on the value itself is what keeps a second run a genuine
     * no-op — an unconditional update would touch every future row's
     * `updated_at` every night, which is both noise and a lie about when the
     * departure last changed.
     *
     * Sold departures are deliberately left alone. Lowering the capacity of a
     * departure somebody has booked onto is how a guest loses a seat they paid
     * for, so it is the operator's decision and it appears in the
     * reconciliation list.
     */
    private function syncUnsoldCapacity(ScheduleRule $rule, Carbon $today): void
    {
        Departure::query()
            ->where('schedule_rule_id', $rule->getKey())
            ->where('local_date', '>=', $today->toDateString())
            ->where('seats_sold', 0)
            ->where('seats_held', 0)
            ->where('capacity', '!=', $rule->effectiveCapacity())
            ->update(['capacity' => $rule->effectiveCapacity()]);
    }

    /**
     * The UTC instants this product already has in the window.
     *
     * Keyed by instant rather than by date: two departures can share a local
     * date and differ by an hour on the fall-back night, and the index this
     * mirrors is on the instant.
     *
     * @return array<string, bool>
     */
    private function existingInstants(ScheduleRule $rule, Carbon $today, int $horizonDays): array
    {
        $from = ScheduleRuleDateIterator::startDate($rule, $today);
        $to = ScheduleRuleDateIterator::endDate($rule, $today, $horizonDays)->copy()->addDay();

        return Departure::query()
            ->where('product_id', $rule->product_id)
            ->where('starts_at_utc', '>=', $from->copy()->setTimezone('UTC')->subDay())
            ->where('starts_at_utc', '<', $to->copy()->setTimezone('UTC')->addDay())
            ->pluck('starts_at_utc')
            ->mapWithKeys(static fn (Carbon $instant): array => [$instant->toDateTimeString() => true])
            ->all();
    }

    /**
     * One departure row, with every derived value snapshotted (§1.9, AVL-55).
     *
     * `capacity`, `min_pax` and `vessel_id` are copies taken now. A later edit
     * to the product or the rule must not silently re-guarantee a departure
     * people have already booked onto, which is what reading them live would do.
     *
     * @return array<string, mixed>
     */
    private function row(ScheduleRule $rule, int $productId, int $vesselId, Carbon $starts, bool $ambiguous, string $timezone, int $capacity, int $minPax, int $duration): array
    {
        $now = Carbon::now();

        return [
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $rule->tenant_id,
            'product_id' => $productId,
            'vessel_id' => $vesselId,
            'schedule_rule_id' => $rule->getKey(),
            'local_date' => LocalDateTimeResolver::localDate($starts, $timezone),
            'local_time' => LocalDateTimeResolver::localTime($starts, $timezone),
            'starts_at_utc' => $starts->toDateTimeString(),
            'ends_at_utc' => LocalDateTimeResolver::endsAt($starts, $duration)->toDateTimeString(),
            'dst_ambiguous' => $ambiguous,
            'capacity' => $capacity,
            'min_pax' => $minPax,
            'seats_sold' => 0,
            'seats_held' => 0,
            'status' => DepartureStatus::Scheduled->value,
            'is_blocked' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
