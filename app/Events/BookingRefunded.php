<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\RefundMethod;
use App\Jobs\ExecuteGatewayRefund;
use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money went back to a guest (spec SEC-16, CXL-10, ADR-0025).
 *
 * ## The gap ADR-0025 left open, closed
 *
 * `booking.refunded` has been on SEC-16's list and in {@see AuditAction} since
 * #53, marked *not live* because its subject did not exist until M2.
 * `NoPersonalDataInAuditContextTest` records that gap deliberately, *"so the
 * milestone which builds them fires an existing action rather than inventing a
 * spelling"*. This is that milestone, and this is that action.
 *
 * ## It fires when the money actually moved
 *
 * Not when a refund was decided, and not when a refund row was written. CXL-10
 * is explicit that a failed gateway refund must not silently mark a booking
 * refunded, and an audit row saying a guest was refunded when they were not is
 * exactly the shape of that failure. {@see ExecuteGatewayRefund}
 * dispatches this only on a successful settlement — and the voucher and waived
 * paths dispatch it too, because credit issued and a refund waived are both
 * decisions a dispute will ask about.
 *
 * ## The subject is passed as a model, and that is safe here
 *
 * Unlike {@see BookingConfirmed}, this is an `Auditable`: the listener builds
 * an {@see AuditEntryData} from it **synchronously in the dispatching process**
 * and queues plain scalars. The model never crosses the queue boundary, which
 * is the whole shape ADR-0025 §2 chose.
 */
final class BookingRefunded implements Auditable
{
    use Dispatchable;

    public function __construct(
        private readonly Booking $booking,
        private readonly int $amountCents,
        private readonly RefundMethod $method,
        private readonly ?string $reason = null,
    ) {}

    public function auditEntry(): AuditEntryData
    {
        return AuditEntryData::forModel(
            action: AuditAction::BookingRefunded,
            subject: $this->booking,
            // The reference, which is what an operator and a guest both say out
            // loud. Never the guest's name: ADR-0025 §3, seven-year retention.
            label: $this->booking->reference,
            reason: $this->reason,
            context: [
                'amount_cents' => $this->amountCents,
                'method' => $this->method->value,
                'cancel_reason' => $this->booking->cancel_reason?->value,
            ],
        );
    }
}
