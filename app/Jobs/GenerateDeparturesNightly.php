<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Extend every active rule to the horizon, nightly (ADR-0009, NFR-10, NFR-11).
 *
 * ## It dispatches rather than generates
 *
 * One job per rule, fanned out, rather than one job walking every tenant. A
 * single job that generated everything would grow with the platform and
 * eventually exceed the 120-second budget (NFR-10) — and when it did, it would
 * fail *as a whole*, losing the tenants it had not reached yet. Fanned out, a
 * slow tenant delays only itself.
 *
 * ## It records its own completion
 *
 * NFR-11 asks that a missed run can raise an alert. A job that simply worked
 * every night leaves nothing to check, so the completion timestamp is written
 * where a monitor can read it. The cache is the right home: this is liveness,
 * not a business record, and a lost entry means "check again", not "regenerate
 * a year of departures".
 */
class GenerateDeparturesNightly implements ShouldQueue
{
    use Queueable;

    /** Where the last successful sweep is recorded, for the alert to read. */
    public const COMPLETED_AT_KEY = 'departures:nightly:completed_at';

    public function handle(): void
    {
        // **Not** wrapped in `withoutTenancy()`. `tenants` is platform-owned and
        // needs no exemption, and suspending the scope here would suspend it
        // inside the per-tenant block too — so every tenant's sweep would read
        // every tenant's rules and dispatch the whole platform's work once per
        // operator. That is not a leak of data, but it is a leak of jobs, and
        // it grows quadratically.
        Tenant::query()
            ->whereNull('deleted_at')
            // Chunked: the tenant list is the one thing that grows with the
            // platform rather than with an operator's calendar.
            ->chunkById(50, function ($tenants): void {
                foreach ($tenants as $tenant) {
                    $this->dispatchRulesFor($tenant);
                }
            });

        Cache::forever(self::COMPLETED_AT_KEY, now()->toIso8601ZuluString());
    }

    private function dispatchRulesFor(Tenant $tenant): void
    {
        Tenancy::forTenant($tenant, function () use ($tenant): void {
            ScheduleRule::query()
                ->active()
                ->select(['id'])
                ->chunkById(200, function ($rules) use ($tenant): void {
                    foreach ($rules as $rule) {
                        GenerateDeparturesForRule::dispatch($tenant->getKey(), $rule->getKey());
                    }
                });
        });
    }
}
