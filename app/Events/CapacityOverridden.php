<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An operator put more people on a boat than it was selling (spec BKG-32, SEC-16).
 *
 * > A manual booking … MUST NOT exceed capacity or the legal `capacity_max`
 * > (AVL-25). **Capacity override requires an explicit confirmation and is
 * > logged.**
 *
 * ## What "is logged" has to mean here
 *
 * Not a log line. The question this row answers — *"why did Saturday's boat
 * have fourteen people on it when we sell twelve?"* — is asked by an operator,
 * months later, looking at a departure that was oversold on purpose. A log line
 * has rotated away by then and they could not read it anyway.
 *
 * So it is an `override.applied` row in the trail ADR-0025 built, beside the
 * refund overrides (CXL-5) and the early check-ins (BKG-22) — three different
 * decisions, one place a person looks.
 *
 * ## The numbers travel with the reason
 *
 * The reason says *why*; `seats_requested` and `capacity` say *how far*. One
 * over a twelve-seat boat and six over are different decisions, and a row
 * carrying only prose makes them look identical.
 */
final class CapacityOverridden implements Auditable
{
    use Dispatchable;

    public function __construct(
        private readonly Booking $booking,
        private readonly string $reason,
    ) {}

    public function auditEntry(): AuditEntryData
    {
        $departure = $this->booking->departure;

        return AuditEntryData::forModel(
            action: AuditAction::OverrideApplied,
            subject: $this->booking,
            label: $this->booking->reference,
            reason: $this->reason,
            // Scalars, no personal data — ADR-0025 §3, enforced by
            // `NoPersonalDataInAuditContextTest`. A guest's name is exactly
            // what a row about "who did we squeeze aboard" would be tempted to
            // carry.
            context: [
                'kind' => 'capacity_override',
                'seats_requested' => $this->booking->pax_capacity_total,
                'capacity' => $departure?->capacity,
                'seats_sold' => $departure?->seats_sold,
            ],
        );
    }
}
