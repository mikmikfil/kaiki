<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Enums\VoucherReason;
use App\Models\Booking;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Support\Facades\DB;

/**
 * Put voucher value back after a cancellation (spec PRC-19.2, PRC-19.4,
 * ADR-0017 Option D).
 *
 * ## Voucher value stays voucher value
 *
 * ADR-0017's whole point. A €200 booking paid with a €120 voucher and €80 cash,
 * cancelled under a 50% policy, gives a €100 entitlement — **€60 back to the
 * voucher and €40 in cash**, pro-rata across the two portions actually used.
 * Not €100 in cash, which would let a guest convert a voucher into money by
 * booking and cancelling; and not €100 in voucher, which would take cash the
 * guest actually paid.
 *
 * The cash half is #84's; this Action is the voucher half, and the split is
 * computed here so that both halves come from one calculation and cannot
 * disagree about the ratio.
 *
 * ## The reversal amends the row rather than adding an opposite one
 *
 * §2.5's unique index on (`tenant_id`, `voucher_id`, `booking_id`) permits one
 * movement per pair. The direction, the amount and the time of the restoration
 * are all still recorded — as `reversed_at` and `reversed_amount_cents` — and
 * **the redemption row is never deleted**, which is what PRC-19.4 actually
 * protects.
 *
 * ## An expired voucher is not resurrected
 *
 * PRC-19.3: if the original has already expired at restoration time the value
 * is issued as a **new** voucher with `force_majeure_voucher_months` validity,
 * linked back through `issued_for_booking_id`. Extending a dead voucher's
 * expiry would quietly rewrite a term the guest already accepted; restoring to
 * a live one does not change its expiry either, for the same reason.
 */
final class RestoreVoucher
{
    public function __construct(private readonly IssueVoucher $issueVoucher) {}

    /**
     * @param  int  $entitlementCents  the total refund the policy snapshot allows
     * @return int the cents returned to voucher value, which may be zero
     */
    public function __invoke(Booking $booking, int $entitlementCents, ?string $reason = null): int
    {
        if ($booking->voucher_id === null || $entitlementCents < 1) {
            return 0;
        }

        /** @var Voucher|null $voucher */
        $voucher = Voucher::query()->lockForUpdate()->find($booking->voucher_id);

        $redemption = $voucher === null ? null : VoucherRedemption::query()
            ->where('voucher_id', $voucher->getKey())
            ->where('booking_id', $booking->getKey())
            ->first();

        if (! $voucher instanceof Voucher || ! $redemption instanceof VoucherRedemption) {
            return 0;
        }

        $voucherShare = self::voucherShareOf($booking, $entitlementCents);

        if ($voucherShare < 1) {
            return 0;
        }

        DB::transaction(function () use ($booking, $voucher, $redemption, $voucherShare, $reason): void {
            $redemption->forceFill([
                'reversed_at' => now(),
                // Cumulative: a booking may be partially restored more than
                // once under an operator override (§5.9), and each restoration
                // adds to what has already gone back rather than replacing it.
                'reversed_amount_cents' => min(
                    $redemption->amount_cents,
                    $redemption->reversed_amount_cents + $voucherShare,
                ),
                'reason' => $reason,
            ])->save();

            if ($voucher->hasExpired()) {
                // PRC-19.3. A dead voucher is not revived; the value is issued
                // fresh and linked back, so the trail from the cancelled
                // booking to the new credit survives.
                $this->issueReplacement($voucher, $booking, $voucherShare, $reason);

                ApplyVoucher::syncRemaining($voucher);

                return;
            }

            ApplyVoucher::syncRemaining($voucher);
        });

        return $voucherShare;
    }

    /**
     * The voucher's pro-rata share of an entitlement (PRC-19.2).
     *
     * The ratio is of what was **actually used**, not of the booking total: a
     * booking whose voucher covered everything restores everything to the
     * voucher, and one paid entirely in cash restores nothing to it.
     *
     * The voucher figure comes from the `voucher_redemptions` **ledger** rather
     * than from `bookings.discount_cents`, which #84 corrected. §2.5 defines
     * that column as *"voucher + manual discount"*, and a manual discount is a
     * price reduction rather than consideration the guest handed over —
     * splitting against it refunds a guest money nobody ever paid.
     *
     * Public and static because #84's cash half is the remainder — computing it
     * as `entitlement − voucherShare` from this same function is what stops the
     * two halves rounding independently and summing to a euro more than the
     * guest is owed.
     */
    public static function voucherShareOf(Booking $booking, int $entitlementCents): int
    {
        $voucherUsed = VoucherRedemption::usedByBooking($booking->getKey());
        $totalUsed = $voucherUsed + $booking->paid_cents;

        if ($totalUsed < 1 || $voucherUsed < 1) {
            return 0;
        }

        // Rounded half up, then clamped: the voucher can never get back more
        // than it put in, whatever the policy says.
        $share = (int) round($entitlementCents * $voucherUsed / $totalUsed);

        return min($voucherUsed, max(0, $share));
    }

    /**
     * A fresh voucher carrying restored value from an expired one (PRC-19.3).
     *
     * The validity used to be read here with a `?? 12` fallback, against a
     * policy whose documented default — in the column, the factory and the
     * snapshot reader — is **18**. {@see IssueVoucher} owns that number now, in
     * one place, and reads it off the booking's own frozen snapshot.
     */
    private function issueReplacement(Voucher $original, Booking $booking, int $cents, ?string $reason): void
    {
        ($this->issueVoucher)(
            booking: $booking,
            cents: $cents,
            reason: VoucherReason::ForceMajeure,
            note: $reason,
            currency: $original->currency,
        );
    }
}
