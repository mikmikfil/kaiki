<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Domain\Pricing\Actions\ComputePrice;
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
 *
 * ## `$depositsEnabled` is a parameter, not a lookup
 *
 * Whether an operator takes deposits at all is `tenants.deposits_enabled`, and
 * this class deliberately does not read it. Reaching for `Tenancy::current()`
 * here would make three lines of arithmetic depend on ambient state, so the
 * same €150 at 30% would answer 4500 or 15000 depending on what happened to be
 * resolved — untestable without a database and unreadable at the call site.
 * {@see ComputePrice} asks the tenant once and
 * passes the answer down. There is no default: a caller that has not decided
 * has not decided, and guessing on their behalf is how a business that never
 * opted into instalments starts issuing them.
 *
 * Switched off, the operator's answer is the one `DepositType::None` already
 * means — **the whole total, now** — rather than a payment of nothing. The rate
 * plan's own type is ignored, not consulted: a plan may say 30% while the
 * business does not work that way, and the business wins.
 */
final class DepositCalculator
{
    /**
     * @return array{type: string, percent: int|null, amount_cents: int}
     */
    public static function forPlan(RatePlan $plan, int $totalCents, bool $depositsEnabled): array
    {
        $type = self::typeFor($plan, $depositsEnabled);
        $amount = self::amountCents($plan, $totalCents, $depositsEnabled);

        return [
            'type' => $type->value,
            'percent' => $type === DepositType::Percent ? $plan->deposit_percent : null,
            'amount_cents' => $amount,
        ];
    }

    /** The amount due now, in cents. */
    public static function amountCents(RatePlan $plan, int $totalCents, bool $depositsEnabled): int
    {
        $total = max(0, $totalCents);

        if ($total === 0) {
            return 0;
        }

        $amount = match (self::typeFor($plan, $depositsEnabled)) {
            // "No deposit" means the guest pays everything now, not nothing.
            DepositType::None => $total,
            DepositType::Percent => max(1, Cents::applyPercent($total, $plan->deposit_percent ?? 0)),
            DepositType::Fixed => max(1, $plan->deposit_fixed_cents ?? 0),
        };

        return min($amount, $total);
    }

    /** What is left to pay later. Zero when the deposit covered everything. */
    public static function balanceCents(RatePlan $plan, int $totalCents, bool $depositsEnabled): int
    {
        return max(0, max(0, $totalCents) - self::amountCents($plan, $totalCents, $depositsEnabled));
    }

    /**
     * The plan's deposit type, unless the operator does not take deposits.
     *
     * One place, so `forPlan()` cannot record `percent` in a snapshot while
     * `amountCents()` charges the whole total — a mismatch a guest would read
     * as "30% deposit" beside a button offering to take all of it.
     */
    private static function typeFor(RatePlan $plan, bool $depositsEnabled): DepositType
    {
        return $depositsEnabled ? $plan->deposit_type : DepositType::None;
    }
}
