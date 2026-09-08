<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Departure;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An operator called a sailing off (SEC-16, AVL-28, CXL-5).
 *
 * The one audited action that is not a deletion, and the one where SEC-16's
 * *"reason where applicable"* most applies: "storm" and "not enough bookings"
 * are different events with different consequences — the first is a weather
 * cancellation with its own refund rule (CXL-5), the second is a commercial
 * decision — and a trail that cannot tell them apart cannot answer the question
 * anybody asks it.
 */
final class DepartureCancelled implements Auditable
{
    use Dispatchable;

    public function __construct(
        private readonly Departure $departure,
        private readonly ?string $reason = null,
    ) {}

    /**
     * The departure this is about.
     *
     * Public because OPS-19 publishes `departure.cancelled` and the webhook
     * listener needs the row. Unlike its neighbours this event carries a model
     * rather than ids — which is why nothing that reads it may be queued; see
     * `App\Listeners\Webhooks\PublishDomainEvent`.
     */
    public function departure(): Departure
    {
        return $this->departure;
    }

    public function auditEntry(): AuditEntryData
    {
        return AuditEntryData::forModel(
            action: AuditAction::DepartureCancelled,
            subject: $this->departure,
            // The sailing, as a human names one. The uuid is in `subject_id`'s
            // company and answers nothing on its own.
            label: $this->departure->local_date->toDateString() . ' ' . substr((string) $this->departure->local_time, 0, 5),
            reason: $this->reason,
            context: [
                // Seats sold at the moment of cancellation: the number that
                // decides how much work the cancellation caused. A count, never
                // a guest.
                'seats_sold' => $this->departure->seats_sold,
                'cancel_reason' => $this->departure->cancel_reason?->value,
            ],
        );
    }
}
