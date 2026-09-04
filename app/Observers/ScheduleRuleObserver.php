<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\GenerateDeparturesForRule;
use App\Models\ScheduleRule;

/**
 * A saved rule generates its departures, immediately and off the request
 * (AVL-54, CNV-10).
 *
 * ADR-0009: *"Saving a rule triggers the same job immediately for that rule."*
 * Queued rather than inline, because a rule saved with a 400-day horizon is
 * four hundred date resolutions and an insert of that many rows — an operator
 * pressing Save should not wait for it, and a request timeout would leave the
 * rule saved and the departures half made.
 *
 * On the model rather than in a provider, so an import or a console command
 * that writes a rule gets its departures too. The panel is not the only writer,
 * and a rule with no departures is a trip that quietly cannot be booked.
 */
final class ScheduleRuleObserver
{
    public function saved(ScheduleRule $rule): void
    {
        $this->dispatch($rule);
    }

    public function restored(ScheduleRule $rule): void
    {
        $this->dispatch($rule);
    }

    private function dispatch(ScheduleRule $rule): void
    {
        // An inactive rule generates nothing, and dispatching a job to discover
        // that is a round trip for a question already answered.
        if (! $rule->is_active) {
            return;
        }

        GenerateDeparturesForRule::dispatch($rule->tenant_id, $rule->getKey());
    }
}
