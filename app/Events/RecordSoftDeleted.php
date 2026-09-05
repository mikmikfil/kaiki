<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Product;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant-owned row was soft-deleted (ADR-0025 §2, spec SEC-16).
 *
 * ADR-0025's scope is SEC-16's five named actions *"plus every soft delete"*,
 * and this is the second half. One event for every model rather than one per
 * model, because the interesting fact — *what* was deleted — is already in
 * `subject_type`, and a class per table would be twenty classes saying the same
 * sentence.
 *
 * ## The action is narrowed for the two SEC-16 names
 *
 * `vessel.deleted` and `product.deleted` are named in SEC-16 and get their own
 * `AuditAction` cases; everything else is `record.deleted`. A reader scanning
 * the trail for "did anyone delete a boat" should not have to know it is
 * spelled generically — and the two that a specification names are the two an
 * auditor asks about.
 *
 * ## Fired from an observer, not from each Action
 *
 * A soft delete can happen from a Filament resource, a Domain Action, an import
 * or a console command, and instrumenting all four is how three of them stay
 * instrumented. `SoftDeleteAuditObserver` fires this from the model's own
 * `deleted` event, which every path goes through.
 */
final class RecordSoftDeleted implements Auditable
{
    use Dispatchable;

    public function __construct(
        private readonly Model $subject,
        private readonly ?string $label = null,
    ) {}

    public function auditEntry(): AuditEntryData
    {
        return AuditEntryData::forModel(
            action: $this->action(),
            subject: $this->subject,
            label: $this->label,
            context: [
                // Whether the row can still be restored. It is the first thing
                // anybody reading this row wants to know, and by then the model
                // is gone and cannot be asked.
                'soft' => true,
            ],
        );
    }

    private function action(): AuditAction
    {
        return match (true) {
            $this->subject instanceof Vessel => AuditAction::VesselDeleted,
            $this->subject instanceof Product => AuditAction::ProductDeleted,
            default => AuditAction::RecordDeleted,
        };
    }
}
