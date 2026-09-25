<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money that arrived when the booking could no longer take it is going back
 * on its own (2026-09-25).
 *
 * `ConfirmFromWebhook` writes the refund and the operator sees it under
 * «Χρειάζονται προσοχή»; this is what tells the guest, who was charged and
 * would otherwise be refunded without a word. Once per refund row: a
 * replayed webhook writes no second row, so it dispatches nothing.
 */
final class LatePaymentRefunded
{
    use Dispatchable;

    /** The booking had been cancelled before the money came in. */
    public const REASON_CANCELLED = 'cancelled';

    /** The checkout ran out and the seats (or the boat) were gone. */
    public const REASON_EXPIRED = 'expired';

    /** Paid more than the booking costs. */
    public const REASON_OVERPAID = 'overpaid';

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
        public readonly int $amountCents,
        public readonly string $reason,
    ) {}
}
