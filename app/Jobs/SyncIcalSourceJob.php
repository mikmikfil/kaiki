<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Availability\Actions\SyncIcalSource;
use App\Models\IcalSource;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One external calendar, pulled in (spec OPS-13, OPS-15, NFR-8).
 *
 * ## Unique per source, because the poller and a person can collide
 *
 * The scheduler dispatches these every fifteen minutes and an operator can
 * press "sync now" in the panel at the same moment. Two syncs of one source
 * running concurrently would both compute the set of vanished events from
 * different snapshots, and the slower one could delete blocks the faster one
 * had just written.
 *
 * ## The retry story is inside the action, not here
 *
 * OPS-15 asks for backoff and for failures to be surfaced after three. Both are
 * properties of the *source row* — `consecutive_failures` survives the job, and
 * a queue retry would not touch it. So this job has one attempt: the action
 * records the failure, the next scheduled poll is the retry, and fifteen
 * minutes is a longer and gentler backoff than any queue setting.
 *
 * That also keeps the semantics honest. A queue retry three minutes later
 * against a feed whose server is down is three failures against one outage,
 * which would trip OPS-15's threshold on a blip.
 */
class SyncIcalSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** See the class docblock: the schedule is the retry. */
    public int $tries = 1;

    public function __construct(public readonly int $icalSourceId) {}

    public function uniqueId(): string
    {
        return 'ical-source:' . $this->icalSourceId;
    }

    public function handle(): void
    {
        $source = Tenancy::withoutTenancy(
            fn (): ?IcalSource => IcalSource::query()->find($this->icalSourceId),
        );

        if (! $source instanceof IcalSource) {
            return;
        }

        // Switched off between dispatch and execution — by an operator, or by
        // the action itself after ten consecutive failures.
        if (! $source->is_active) {
            return;
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($source->tenant_id),
        );

        if (! $tenant instanceof Tenant) {
            return;
        }

        // Inside the tenant: every block written here goes through the global
        // scope, and the source's timezone comes off the tenant row.
        Tenancy::forTenant($tenant, function () use ($source): void {
            app(SyncIcalSource::class)($source);
        });
    }
}
