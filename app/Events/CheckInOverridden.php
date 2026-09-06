<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Domain\Booking\Data\CheckInOverride;
use App\Enums\AuditAction;
use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Crew checked somebody in before the window opened (spec BKG-22, SEC-16).
 *
 * ## "Which is logged" is the whole of the requirement
 *
 * BKG-22 permits the early check-in and then attaches one condition to it. The
 * condition is not a log line — a log line is rotated away and cannot be read
 * by the operator whose boat it was. It is an `override.applied` row in the
 * trail ADR-0025 built, beside the refund overrides, which is where somebody
 * asking *"why does the manifest say this guest boarded an hour before the
 * boat"* will actually look.
 *
 * ## Fired per guest, not per booking
 *
 * Unlike {@see BookingCheckedIn}. Two guests waved through early are two
 * decisions, possibly by two different crew members with two different reasons,
 * and collapsing them into one row would lose whichever reason came second.
 */
final class CheckInOverridden implements Auditable
{
    use Dispatchable;

    public function __construct(
        private readonly Booking $booking,
        private readonly CheckInOverride $override,
        private readonly int $minutesEarly,
    ) {}

    public function auditEntry(): AuditEntryData
    {
        return AuditEntryData::forModel(
            action: AuditAction::OverrideApplied,
            subject: $this->booking,
            label: $this->booking->reference,
            reason: $this->override->reason,
            context: $this->override->auditContext($this->minutesEarly),
        );
    }
}
