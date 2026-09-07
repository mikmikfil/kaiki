<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\PaymentGatewayName;
use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An operator said money arrived (spec BKG-33, OPS-5, SEC-16).
 *
 * ## Why this is in the audit trail at all
 *
 * Every other succeeded payment in the system has a third party behind it. A
 * Viva or Stripe row can be checked against a settlement file, and if the
 * database and the file disagree, the file wins.
 *
 * These cannot. Cash on a boat and a bank transfer somebody eyeballed are a
 * *person's statement* that money arrived, and the only corroboration is who
 * made it and when. That is the row this event writes, and it is the row an
 * accountant asks for in March about a Saturday in July.
 *
 * ## The amount travels, the guest does not
 *
 * ADR-0025 §3 and `NoPersonalDataInAuditContextTest`: scalars only. The booking
 * reference is the label, which is how a person finds the booking; the guest's
 * name is exactly what a row about money would be tempted to carry and exactly
 * what must not be in it.
 */
final class ManualPaymentRecorded implements Auditable
{
    use Dispatchable;

    public function __construct(
        private readonly Booking $booking,
        private readonly int $amountCents,
        private readonly PaymentGatewayName $gateway,
        private readonly string $paymentUuid,
        private readonly ?string $reference = null,
    ) {}

    public function auditEntry(): AuditEntryData
    {
        return AuditEntryData::forModel(
            action: AuditAction::PaymentRecorded,
            subject: $this->booking,
            label: $this->booking->reference,
            context: [
                'payment_uuid' => $this->paymentUuid,
                'amount_cents' => $this->amountCents,
                'gateway' => $this->gateway->value,
                // A bank reference or a receipt number. It is the operator's
                // own bookkeeping handle and the one thing that makes a
                // disputed row checkable months later.
                'reference' => $this->reference,
                'total_cents' => $this->booking->total_cents,
                'paid_cents' => $this->booking->paid_cents,
            ],
        );
    }
}
