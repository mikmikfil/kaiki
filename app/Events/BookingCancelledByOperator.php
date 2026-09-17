<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\RefundMethod;
use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An operator ended a booking, or part of one, from the panel (2026-09-17).
 *
 * Scalars in the context and never a guest's name (ADR-0025 §3): which
 * refund was chosen, the percentage the policy gave and the one applied, how
 * many people were affected, and what came back. The operator's own reason,
 * when there is one, travels in the reason column.
 */
final class BookingCancelledByOperator implements Auditable
{
    use Dispatchable;

    public function __construct(
        private readonly Booking $booking,
        private readonly string $kind,
        private readonly RefundMethod $method,
        private readonly int $policyPercent,
        private readonly int $appliedPercent,
        private readonly int $refundCents,
        private readonly int $people,
        private readonly ?string $reason = null,
    ) {}

    public function auditEntry(): AuditEntryData
    {
        return AuditEntryData::forModel(
            action: AuditAction::BookingCancelled,
            subject: $this->booking,
            label: $this->booking->reference,
            context: [
                'kind' => $this->kind,
                'method' => $this->method->value,
                'policy_percent' => $this->policyPercent,
                'applied_percent' => $this->appliedPercent,
                'refund_cents' => $this->refundCents,
                'people' => $this->people,
            ],
            reason: $this->reason,
        );
    }
}
