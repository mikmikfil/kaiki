<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Booking\Support\LockOrder;
use App\Enums\VoucherStatus;
use App\Models\Booking;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Support\Facades\DB;

/**
 * Spend a voucher against a booking (spec PRC-18, PRC-19, PRC-20, ADR-0017).
 *
 * ## It must be called inside the caller's transaction, holding a row lock
 *
 * PRC-20: *"re-validated inside the confirmation transaction … with a row lock,
 * so two concurrent bookings cannot spend the same voucher twice."* Without the
 * lock both read €50 remaining, both apply €50, and the operator has given away
 * €100 — the overselling bug in a different table, and it is covered by the
 * same MySQL-only concurrency test.
 *
 * The voucher is the **last** lock in {@see LockOrder},
 * after vessel, departure and booking.
 *
 * ## Voucher value stays voucher value
 *
 * ADR-0017's headline, and PRC-19's first line: *"neither is ever converted
 * into the other."* So `applied_cents = min(remaining, total)` and any surplus
 * **stays on the voucher** for a later booking. It is never forfeited and never
 * paid out in cash, and the booking total can never go below zero.
 *
 * ## `remaining_cents` is recomputed from the ledger, never decremented
 *
 * PRC-19.4 requires it to be reconstructible at all times, and a decrement
 * carries forward whatever drift the column has. The recomputation is one
 * indexed query and is right whatever happened before it — the same reasoning
 * `HoldSeats` applies to `seats_held` and `ConfirmBooking` to `paid_cents`.
 *
 * ## Idempotent, because both checkout and confirmation call it
 *
 * The unique index on (`tenant_id`, `voucher_id`, `booking_id`) permits one
 * movement per pair, so re-applying updates that row rather than spending
 * again. A guest who returns to a failed checkout must not consume their
 * voucher twice on the way through.
 */
final class ApplyVoucher
{
    /** @return int the cents applied, which may be zero */
    public function __invoke(Booking $booking): int
    {
        if ($booking->voucher_id === null) {
            return 0;
        }

        /** @var Voucher|null $voucher */
        $voucher = Voucher::query()->lockForUpdate()->find($booking->voucher_id);

        if (! $voucher instanceof Voucher) {
            // The voucher was deleted between draft and checkout. The booking
            // keeps its total and simply has no discount — refusing the sale
            // over the operator's own housekeeping would be worse.
            $this->clearDiscount($booking);

            return 0;
        }

        $existing = VoucherRedemption::query()
            ->where('voucher_id', $voucher->getKey())
            ->where('booking_id', $booking->getKey())
            ->first();

        // Re-read from the ledger, excluding whatever this booking already
        // holds — it is being recomputed, not stacked on top of itself.
        $consumedElsewhere = VoucherRedemption::consumedFor($voucher->getKey())
            - ($existing?->netCents() ?? 0);

        $availableNow = max(0, $voucher->amount_cents - $consumedElsewhere);

        // **`Redeemed` is deliberately not a refusal here**, and getting that
        // wrong cost this Action its idempotency.
        //
        // `status` is *derived* from `remaining_cents`, which is derived from
        // the ledger — so a voucher this very booking consumed reads as
        // `Redeemed` the moment it is applied. On the second call (checkout
        // applies it, confirmation re-validates it, a guest returns from a
        // declined card) a plain `isSpendable()` check therefore refused the
        // booking its own discount, silently cleared it, and put the total back
        // up. A test caught it only after the zero-total path arrived at a
        // gateway with €50 to charge.
        //
        // `$availableNow` already encodes the truth, because it excludes this
        // booking's own share. What remains are the two states that are real
        // refusals whatever the ledger says: cancelled by the operator, and
        // expired.
        $refused = $voucher->status === VoucherStatus::Cancelled
            || $voucher->hasExpired()
            || $availableNow < 1;

        if ($refused) {
            $this->clearDiscount($booking);
            $this->syncRemaining($voucher);

            return 0;
        }

        // PRC-18: applied against the total *after* extras and *before* the
        // deposit, which PRC-25 settles — a voucher reduces both, so a guest
        // does not pay a deposit on money they already hold.
        $gross = $booking->subtotal_cents + $booking->extras_cents;
        $applied = min($availableNow, $gross);

        DB::transaction(function () use ($booking, $voucher, $existing, $applied): void {
            if ($existing instanceof VoucherRedemption) {
                $existing->forceFill([
                    'amount_cents' => $applied,
                    'reversed_at' => null,
                    'reversed_amount_cents' => 0,
                    'redeemed_at' => now(),
                ])->save();
            } else {
                VoucherRedemption::query()->create([
                    'voucher_id' => $voucher->getKey(),
                    'booking_id' => $booking->getKey(),
                    'amount_cents' => $applied,
                    'redeemed_at' => now(),
                ]);
            }

            $booking->forceFill([
                'discount_cents' => $applied,
                // Never below zero (PRC-19.1), and `max` rather than an
                // assertion because a voucher larger than the total is the
                // ordinary case rather than an error.
                'total_cents' => max(0, $booking->subtotal_cents + $booking->extras_cents - $applied),
            ]);

            $booking->forceFill([
                'balance_cents' => max(0, $booking->total_cents - $booking->paid_cents),
            ])->save();

            $this->syncRemaining($voucher);
        });

        return $applied;
    }

    /** The voucher went, or cannot be spent. The sale continues at full price. */
    private function clearDiscount(Booking $booking): void
    {
        if ($booking->discount_cents === 0) {
            return;
        }

        $booking->forceFill([
            'discount_cents' => 0,
            'total_cents' => $booking->subtotal_cents + $booking->extras_cents,
        ]);

        $booking->forceFill([
            'balance_cents' => max(0, $booking->total_cents - $booking->paid_cents),
        ])->save();
    }

    /**
     * Rebuild `remaining_cents` from the ledger (PRC-19.4).
     *
     * Public and static so cancellation, restoration and the nightly
     * reconciler all reach the same figure. One definition, four callers.
     */
    public static function syncRemaining(Voucher $voucher): void
    {
        $remaining = max(0, $voucher->amount_cents - VoucherRedemption::consumedFor($voucher->getKey()));

        // Fully spent is a status an operator can see, and it is **derived**
        // rather than set: a voucher whose ledger is later reversed becomes
        // active again without anybody remembering to flip it back. Cancelled
        // and expired are left alone — those are decisions, not arithmetic.
        $status = match (true) {
            $voucher->status === VoucherStatus::Cancelled => $voucher->status,
            $voucher->status === VoucherStatus::Expired => $voucher->status,
            $remaining === 0 => VoucherStatus::Redeemed,
            default => VoucherStatus::Active,
        };

        $voucher->forceFill([
            'remaining_cents' => $remaining,
            'status' => $status,
        ])->save();
    }
}
