<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Departure;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An operator chose to sail short of the minimum (SEC-16, 2026-09-17).
 *
 * An `override.applied` row beside the capacity and refund overrides: the rule
 * said this sailing was not viable and a person decided otherwise. The numbers
 * travel with it — one short of six and five short of six are different
 * decisions — and never a guest.
 */
final class MinimumWaived implements Auditable
{
    use Dispatchable;

    /** Who decided is on the audit row already, from the request context. */
    public function __construct(
        private readonly Departure $departure,
    ) {}

    public function auditEntry(): AuditEntryData
    {
        return AuditEntryData::forModel(
            action: AuditAction::OverrideApplied,
            subject: $this->departure,
            label: $this->departure->local_date->toDateString() . ' ' . substr((string) $this->departure->local_time, 0, 5),
            context: [
                'kind' => 'min_pax_waived',
                'seats_sold' => $this->departure->seats_sold,
                'min_pax' => $this->departure->min_pax,
            ],
        );
    }
}
