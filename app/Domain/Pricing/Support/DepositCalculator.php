<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Enums\DepositType;
use App\Models\RatePlan;
use App\Support\Money\Cents;

/**
 * What the guest pays now (spec PRC-23, PRC-24).
 *
 * ## Every clamp here is a real failure it prevents
 *
 * - **A fixed deposit above the total** is an operator's standing €200 on a
 *   €150 seat. Clamped to the total, and the balance is then zero — PRC-24
 *   calls that full payment, which is exactly what it is.
 * - **A percentage that rounds to nothing** — 1% of €0.50 — would take a
 *   payment of zero and leave the booking looking paid. Floored at one cent, so
 *   a deposit that exists is a deposit that was charged.
 * - **A zero total** takes no deposit at all. There is nothing to hold, and a
 *   one-cent charge against a free booking is a payment row nobody can explain.
 *
 * PRC-25 fixes that the deposit is computed **after** any voucher, which is why
 * this takes the total rather than the subtotal. Vouchers are M2; the ordering
 * is already correct here so that adding them changes nothing in this file.
 */
final class DepositCalculator
{
    /**
     * @return array{type: string, percent: int|null, amount_cents: int}
     */
    public static function forPlan(RatePlan $plan, int $totalCents): array
    {
        $type = $plan->deposit_type;
        $amount = self::amountCents($plan, $totalCents);

        return [
            'type' => $type->value,
            'percent' => $type === DepositType::Percent ? $plan->deposit_percent : null,
            'amount_cents' => $amount,
        ];
    }

    /** The amount due now, in cents. */
    public static function amountCents(RatePlan $plan, int $totalCents): int
    {
        $total = max(0, $totalCents);

        if ($total === 0) {
            return 0;
        }

        $amount = match ($plan->deposit_type) {
            // "No deposit" means the guest pays everything now, not nothing.
            DepositType::None => $total,
            DepositType::Percent => max(1, Cents::applyPercent($total, $plan->deposit_percent ?? 0)),
            DepositType::Fixed => max(1, $plan->deposit_fixed_cents ?? 0),
        };

        return min($amount, $total);
    }

    /** What is left to pay later. Zero when the deposit covered everything. */
    public static function balanceCents(RatePlan $plan, int $totalCents): int
    {
        return max(0, max(0, $totalCents) - self::amountCents($plan, $totalCents));
    }
}
