<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Actions;

use App\Domain\Analytics\Support\AnalyticsMetric;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Add one to a day's count (ADR-0032).
 *
 * ## Increment first, insert second
 *
 * The obvious form is an upsert, and it is the one thing this project cannot
 * write portably: Laravel's `upsert()` becomes `ON DUPLICATE KEY UPDATE` on
 * MySQL and `ON CONFLICT DO UPDATE` on SQLite, and a raw `count + 1` inside the
 * update half is spelled differently by each grammar. ADR-0015 rule 3 is the
 * rule that keeps engine-specific SQL out of this codebase, and it applies here
 * as much as in a migration.
 *
 * So: try to increment the row, and insert it when there was nothing to
 * increment. `increment()` is one atomic statement on both engines, which is
 * what makes two workers counting the same second safe.
 *
 * ## The insert can still lose a race, and that is handled rather than avoided
 *
 * Two requests arriving on the same fresh day both find nothing to increment
 * and both insert; the unique index refuses the second. The loser catches it
 * and increments, which is the row the winner just made. The alternative — a
 * lock, or a transaction per beacon — would put a write lock in front of a page
 * view, which is the one thing an analytics counter must never do.
 *
 * ## It never throws at the caller
 *
 * A beacon that could break a booking page is worse than no analytics at all,
 * so a failure past the retry is swallowed. The count is the only thing lost.
 */
final class CountAnalyticsEvent
{
    public const TABLE = 'analytics_daily';

    /**
     * @param  Tenant  $tenant  whose day this is
     * @param  string  $dimension  what the count is broken down by, or `''`
     * @param  string  $dimensionValue  the value of that dimension, or `''`
     * @param  int  $valueCents  money to add, for a metric that carries it
     */
    public function __invoke(
        Tenant $tenant,
        AnalyticsMetric $metric,
        string $dimension = '',
        string $dimensionValue = '',
        int $valueCents = 0,
    ): void {
        // The tenant's own calendar day, so a count lands in the same bucket
        // every other figure on the statistics page uses.
        $date = Carbon::now($tenant->timezone ?: config('app.timezone', 'UTC'))->toDateString();

        $point = [
            'tenant_id' => $tenant->getKey(),
            'date' => $date,
            'metric' => $metric->value,
            // Truncated rather than refused: a dimension value is a label on a
            // count, and losing the tail of a long one costs less than losing
            // the count.
            'dimension' => mb_substr($dimension, 0, 32),
            'dimension_value' => mb_substr($dimensionValue, 0, 64),
        ];

        try {
            if ($this->add($point, $valueCents) === 0) {
                $this->start($point, $valueCents);
            }
        } catch (QueryException) {
            // Somebody else inserted the same point between the two statements
            // above. Their row is the one to add to.
            try {
                $this->add($point, $valueCents);
            } catch (QueryException) {
                // Nothing here is worth an exception in front of a guest.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $point
     */
    private function add(array $point, int $valueCents): int
    {
        // `increment` with extra columns is one statement: `count = count + 1,
        // value_cents = value_cents + ?`. Two calls would be two statements and
        // a window between them.
        return DB::table(self::TABLE)
            ->where($point)
            ->increment('count', 1, [
                'value_cents' => DB::raw('value_cents + ' . max(0, $valueCents)),
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $point
     */
    private function start(array $point, int $valueCents): void
    {
        DB::table(self::TABLE)->insert([
            ...$point,
            'count' => 1,
            'value_cents' => max(0, $valueCents),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}
