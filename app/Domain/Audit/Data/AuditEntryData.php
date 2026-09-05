<?php

declare(strict_types=1);

namespace App\Domain\Audit\Data;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/**
 * What an auditable event wants recorded (ADR-0025 §2).
 *
 * A value object rather than the event building an {@see AuditLog} itself, and
 * the reason is that the listener is **queued**: an Eloquent model in an event
 * payload is serialised and re-fetched on the worker, and the whole point of
 * half these rows is that the subject has just been deleted. By the time the
 * job runs there is nothing to re-fetch. So an event captures what it knows at
 * the moment it fires — including `subjectLabel`, which is the boat's name
 * *before* the row went away — and hands over plain scalars.
 *
 * ## `context` is a small map with no personal data, and that is enforced
 *
 * ADR-0025 §3: no guest names, no passport numbers, no email addresses. The
 * retention window is seven years, so anything put here outlives every other
 * copy of it, and the legal basis for keeping it is the operator's bookkeeping
 * obligation rather than consent. `NoPersonalDataInAuditContextTest` scans the
 * events for the shapes that go wrong, in the manner of
 * `NoHardcodedVatRateTest`.
 *
 * `reason` is the exception and is a column of its own: SEC-16 asks for the
 * operator's own words *where applicable*, and an operator who types a guest's
 * name into a cancellation reason has made a choice this code cannot second
 * guess. Keeping it out of `context` means the scanner can be strict about the
 * part that is ours.
 */
final class AuditEntryData extends Data
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public readonly AuditAction $action,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly ?string $subjectLabel = null,
        public readonly ?string $reason = null,
        public readonly array $context = [],
    ) {}

    /**
     * Build one from a model, capturing its label before it disappears.
     *
     * `subjectType` is the model's **morph alias** where one is registered and
     * its short class name otherwise — never the fully qualified class. A
     * namespace in a database column is a namespace that cannot be refactored,
     * and this table is kept for seven years.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function forModel(
        AuditAction $action,
        Model $subject,
        ?string $label = null,
        ?string $reason = null,
        array $context = [],
    ): self {
        return new self(
            action: $action,
            subjectType: $subject->getMorphClass() === $subject::class
                ? class_basename($subject)
                : $subject->getMorphClass(),
            subjectId: is_numeric($subject->getKey()) ? (int) $subject->getKey() : null,
            subjectLabel: $label,
            reason: $reason,
            context: $context,
        );
    }
}
