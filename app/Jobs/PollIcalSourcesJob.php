<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IcalSource;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * The fifteen-minute sweep across every operator's calendar sources
 * (spec OPS-13).
 *
 * ## It dispatches rather than syncs
 *
 * One job per source, not one long job that fetches them all in a loop. Two
 * reasons, and the second is the one that bites:
 *
 * - A platform of a few hundred operators is a few hundred HTTP calls to
 *   servers nobody here controls. In a loop that is one job holding a worker
 *   for minutes; fanned out it is short jobs a queue can spread.
 * - **One slow feed must not delay every feed behind it.** A loop stops at the
 *   first fifteen-second timeout and the operators later in the list get their
 *   calendar minutes late — every quarter of an hour, for ever, invisibly.
 *
 * ## Cross-tenant, and it must be
 *
 * There is no current tenant in the scheduler. `dueForSync` deliberately does
 * not filter by one — it is the same shape as the export purge sweeper — and
 * each dispatched job re-enters its own tenant.
 */
class PollIcalSourcesJob implements ShouldQueue
{
    use Queueable;

    /**
     * How many sources one sweep dispatches.
     *
     * A ceiling rather than a target. It exists so that a platform that has
     * grown past what a fifteen-minute window can absorb degrades into a
     * backlog that drains, rather than into a queue with ten thousand jobs
     * dispatched at once — and `dueForSync` orders by staleness, so the feeds
     * that waited longest go first and nobody is starved.
     */
    private const BATCH = 500;

    public function handle(?Carbon $now = null): void
    {
        $now ??= Carbon::now();

        Tenancy::withoutTenancy(function () use ($now): void {
            IcalSource::query()
                ->dueForSync($now)
                ->orderByRaw('last_synced_at is null desc')
                ->orderBy('last_synced_at')
                ->limit(self::BATCH)
                ->get()
                ->each(static function (IcalSource $source): void {
                    SyncIcalSourceJob::dispatch($source->getKey());
                });
        });
    }
}
