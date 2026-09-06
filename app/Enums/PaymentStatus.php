<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a payment stands (`docs/data-model.md` §2.5, PAY-8).
 *
 * ## Only `succeeded` counts toward `paid_cents`
 *
 * PAY-10, and it is the whole reason this enum exists rather than a boolean.
 * `pending` is a row created **before** the redirect, so that a guest who
 * closes the tab leaves evidence rather than nothing; `processing` is a gateway
 * that has taken the money and not yet settled it. Counting either as paid
 * would mark a booking settled on the strength of somebody having *started* to
 * pay.
 *
 * `cancelled` and `failed` are kept apart because an operator chasing an unpaid
 * balance needs to know whether the card was declined or the guest walked away —
 * one of those is worth a phone call.
 */
enum PaymentStatus: string
{
    use HasTranslatedLabel;

    /** Created before the redirect. Nothing has been taken. */
    case Pending = 'pending';

    /** The gateway has the money and has not settled it. */
    case Processing = 'processing';

    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Does this row contribute to `paid_cents`?
     *
     * PAY-10: `paid_cents` is the sum of succeeded non-refund payments minus
     * succeeded refunds, recomputed inside the transaction and never
     * incremented blindly.
     */
    public function countsAsPaid(): bool
    {
        return $this === self::Succeeded;
    }

    /** Is this payment still capable of becoming `succeeded`? */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }
}
