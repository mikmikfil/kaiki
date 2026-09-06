<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Domain\Booking\Data\RefundOverride;
use App\Enums\AuditAction;
use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An operator overruled the policy, and said why (spec CXL-5, SEC-16).
 *
 * ## The reason is the row
 *
 * CXL-5 is FIXED: an override *"requires a reason, which is stored and shown in
 * the booking timeline"*. {@see RefundOverride} refuses to exist without one,
 * so by the time this event is constructed the reason is guaranteed; what this
 * adds is the **record**, in `audit_logs.reason`, which is the column SEC-16
 * asked for and the one the timeline reads.
 *
 * ## Both numbers, not just the applied one
 *
 * `policy_percent` and `applied_percent` travel together. A row saying "40%"
 * says nothing about whether that was the policy or a decision; the pair says
 * which, and a dispute a year later is entirely about which.
 *
 * This fires **whether or not any money moves** — a waiver moves none and is
 * the override most likely to be questioned.
 */
final class RefundOverridden implements Auditable
{
    use Dispatchable;

    public function __construct(
        private readonly Booking $booking,
        private readonly RefundOverride $override,
        private readonly int $policyPercent,
    ) {}

    public function auditEntry(): AuditEntryData
    {
        return AuditEntryData::forModel(
            action: AuditAction::OverrideApplied,
            subject: $this->booking,
            label: $this->booking->reference,
            reason: $this->override->reason,
            context: $this->override->auditContext($this->policyPercent),
        );
    }
}
