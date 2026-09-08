<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Picks up deliveries whose delayed job was lost (spec OPS-20, NFR-8).
 *
 * ## Why this is needed at all
 *
 * {@see DeliverWebhook} schedules its own next attempt by re-dispatching itself
 * with a delay, which is the right shape — the panel can show which attempt a
 * delivery is on and when the next one is due, and a published schedule lives
 * somewhere a person can read it.
 *
 * The cost is that the pending attempt now exists in **two** places: the row,
 * and a delayed job on the queue. Rows survive anything; delayed jobs do not.
 * Flush the queue during a deploy, lose a Redis instance, or run the eight-hour
 * gap of §8.4's later attempts across an infrastructure change, and the row
 * still says "next attempt at 14:20" with nothing left to make it happen. The
 * delivery would sit `pending` for ever, invisible to the failure feed because
 * nothing failed.
 *
 * So the row is the source of truth and this is what enforces it: anything due
 * and still pending gets a job, whether or not it already has one.
 *
 * ## A duplicate job is harmless, which is what makes this safe
 *
 * `DeliverWebhook` returns immediately for a delivery that is no longer
 * pending, and the unique index on `(webhook_endpoint_id, event_id)` means
 * there is one row per event per endpoint to be pending in the first place. So
 * the worst this can do is run a job that finds its work already done.
 *
 * ## Cross-tenant, deliberately
 *
 * `wh_deliveries_retry_idx` is `(status, next_attempt_at)` with no tenant
 * column in front, for this query. Asking once per operator would be one scan
 * per operator every minute, which is the cost this index exists to avoid.
 */
class SweepWebhookRetriesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A ceiling per run.
     *
     * A queue that has been down for a day comes back to a backlog, and
     * dispatching all of it in one job is how a recovery becomes a second
     * outage. The next run a minute later takes the next batch.
     */
    public const BATCH = 500;

    public function handle(): void
    {
        Tenancy::withoutTenancy(function (): void {
            WebhookDelivery::query()
                ->withoutGlobalScopes()
                ->due()
                ->orderBy('next_attempt_at')
                ->limit(self::BATCH)
                ->pluck('id')
                ->each(static fn (int $id) => DeliverWebhook::dispatch($id));
        });
    }
}
