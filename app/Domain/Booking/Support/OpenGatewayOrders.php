<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Actions\StartCheckout;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;

/**
 * The card pages still open on a booking whose price just changed (2026-09-25).
 *
 * A gateway order carries the amount it was minted with. When the total moves
 * under it — a discount code applied after the guest pressed Back, guests taken
 * off by the operator — that order would charge the old figure. It is withdrawn
 * here, and the next «Πληρωμή» mints a new one at the right amount
 * ({@see StartCheckout}).
 *
 * The gateway contract has no call to cancel an order, so a guest may still pay
 * the old tab. `ConfirmFromWebhook` confirms the booking and gives back
 * whatever was paid on top of the total.
 *
 * Called where the total changes, inside the booking's row lock when the caller
 * holds one.
 */
final class OpenGatewayOrders
{
    /** @return int how many were withdrawn */
    public static function withdraw(Booking $booking): int
    {
        return Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('kind', '!=', PaymentKind::Refund->value)
            ->open()
            ->update(['status' => PaymentStatus::Cancelled->value, 'updated_at' => now()]);
    }
}
