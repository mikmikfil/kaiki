<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Availability\Actions\GenerateDepartures;
use App\Models\ScheduleRule;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generate one rule's departures, off the request (AVL-54, CNV-10).
 *
 * ## It carries ids, not models
 *
 * A queued job is serialised, and a serialised Eloquent model is a snapshot of
 * a row that may have changed by the time the worker picks it up. Worse for us:
 * `SerializesModels` reloads through the global tenant scope, which is not
 * initialised in a worker process — the reload would find nothing and the job
 * would fail with a message about a missing model rather than about tenancy.
 *
 * So the job takes two integers and opens the tenant itself.
 *
 * ## Unique while queued
 *
 * Saving a rule three times in a minute should generate once, not three times.
 * The work is idempotent either way, so this is about not doing it twice rather
 * than about correctness.
 */
class GenerateDeparturesForRule implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $scheduleRuleId,
    ) {}

    /** One queued job per rule, however many saves arrive. */
    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->scheduleRuleId}";
    }

    public function handle(GenerateDepartures $generate): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($generate): void {
            $rule = ScheduleRule::query()->find($this->scheduleRuleId);

            // Deleted between dispatch and execution, which is ordinary rather
            // than exceptional — a rule an operator created and thought better
            // of.
            if ($rule !== null) {
                $generate($rule);
            }
        });
    }
}
