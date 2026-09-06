<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Data\Pricing\CancellationPolicyData;
use App\Domain\Pricing\Actions\RestoreVoucher;
use App\Domain\Pricing\Support\RefundCalculator;
use App\Models\Booking;
use App\Models\VoucherRedemption;
use Illuminate\Support\Carbon;

/**
 * What a cancelled booking is owed, split once (spec CXL-3, PRC-19.2).
 *
 * ## One calculation, two halves, and they cannot disagree
 *
 * ADR-0017's example is the shape of the whole problem: €200 paid with a €120
 * voucher and €80 cash, cancelled under a 50% policy, is a €100 entitlement
 * that must come apart as **€60 to the voucher and €40 in cash**. Compute the
 * two halves independently and they round independently, and the guest is owed
 * a euro more or less than the operator gave back — every time, quietly, on
 * exactly the bookings where somebody is already unhappy.
 *
 * So the split happens once, here: the voucher share comes from
 * {@see RestoreVoucher::voucherShareOf()} and **the cash share is the
 * remainder**. Not "the cash proportion of the entitlement" — the remainder.
 * That is what makes the two sum to the entitlement by construction rather than
 * by luck.
 *
 * ## The base is what the guest gave up, which reconciles CXL-3.3 with ADR-0017
 *
 * These two read as though they disagree, and the disagreement is worth stating
 * because getting it wrong is money.
 *
 * CXL-3.3: *"The base is the amount actually paid in cash, not the booking
 * total."* ADR-0017's worked example: €200 paid with a €120 voucher and €80
 * cash, cancelled under a 50% policy, gives **€60 to the voucher and €40 in
 * cash** — a €100 entitlement, which is half of €200 rather than half of the
 * €80 of cash.
 *
 * Read literally against a voucher-paid booking, CXL-3.3 would return €40 and
 * contradict the ADR. But what CXL-3.3 is contrasting `paid_cents` **with** is
 * the *price*: it exists to stop a guest who paid a 30% deposit being refunded
 * half of a trip they have not paid for. A voucher is not an unpaid balance —
 * it is consideration the guest handed over — so the base here is
 * `cash + voucher actually redeemed`, which satisfies CXL-3.3's actual concern
 * and reproduces the ADR's example exactly.
 *
 * For a booking with no voucher the two readings are identical, which is every
 * booking CXL-3.3 was written about.
 *
 * The voucher figure comes from the `voucher_redemptions` ledger, not from
 * `bookings.discount_cents`: §2.5 defines that column as *"voucher + manual
 * discount"*, and a manual discount is a price reduction rather than money the
 * guest gave. Splitting against it would refund cash nobody ever paid.
 *
 * ## Nothing here reads a policy from the database
 *
 * CXL-1. The snapshot comes off the booking, through
 * {@see CancellationPolicyData::fromSnapshot()}, and
 * {@see RefundCalculator} would not accept a model even if one were offered.
 */
final class RefundEntitlement
{
    private function __construct(
        public readonly int $percent,
        public readonly int $totalCents,
        public readonly int $voucherCents,
        public readonly int $cashCents,
    ) {}

    /**
     * The ordinary guest cancellation (CXL-3), at `$cancelledAt`.
     */
    public static function forCancellation(Booking $booking, ?Carbon $cancelledAt = null): self
    {
        $policy = self::policyOf($booking);
        $at = $cancelledAt ?? now();

        return self::atPercent(
            $booking,
            RefundCalculator::percentFor($policy, $booking->starts_at_utc, $at),
        );
    }

    /**
     * The weather rule (CXL-6): the snapshot's own `weather_refund_percent`.
     *
     * **Each booking's own snapshot**, which is why this takes a booking and
     * not a departure. Two guests on the same cancelled sailing who booked
     * under different policies are owed different proportions, and a workflow
     * that read the departure's product's current policy would give them both
     * the same wrong answer.
     */
    public static function forWeather(Booking $booking): self
    {
        return self::atPercent($booking, self::policyOf($booking)->weatherRefundPercent);
    }

    /**
     * An explicit percentage — the operator override (CXL-5) and the weather
     * rule both arrive here.
     */
    public static function atPercent(Booking $booking, int $percent): self
    {
        $percent = max(0, min(100, $percent));

        // See the class docblock: cash plus the voucher value actually
        // redeemed, never the price.
        $base = $booking->paid_cents + VoucherRedemption::usedByBooking($booking->getKey());

        $total = RefundCalculator::applyPercent($base, $percent);

        $voucher = RestoreVoucher::voucherShareOf($booking, $total);

        return new self(
            percent: $percent,
            totalCents: $total,
            voucherCents: $voucher,
            // **The remainder**, not a second proportion — and clamped at the
            // cash actually received, which is the guarantee that matters: a
            // guest must never be handed money the operator never took.
            cashCents: max(0, min($booking->paid_cents, $total - $voucher)),
        );
    }

    /** The frozen policy, or an empty one for a booking that never had a snapshot. */
    public static function policyOf(Booking $booking): CancellationPolicyData
    {
        return CancellationPolicyData::fromSnapshot($booking->policy_snapshot ?? []);
    }

    /** Is there anything at all to give back? */
    public function isEmpty(): bool
    {
        return $this->totalCents < 1;
    }
}
