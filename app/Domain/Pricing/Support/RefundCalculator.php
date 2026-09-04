<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Support;

use App\Data\Pricing\CancellationPolicyData;
use App\Data\Pricing\CancellationTierData;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Carbon;

/**
 * What a guest gets back, and nothing else (spec CXL-3, CXL-1).
 *
 * ## It cannot reach the database, by construction
 *
 * Every method is static and every input is a value. There is no constructor,
 * no container binding and no model — so the same function that prices a
 * refund today is the function M2 runs against a snapshot frozen months ago,
 * and CXL-1's *"editing a policy MUST NOT affect any existing booking"* is a
 * property of the type signature rather than a rule somebody has to remember.
 *
 * If this class ever grows a repository, the invariant is already broken.
 *
 * ## The three steps of CXL-3, in order
 *
 * 1. **Free cancellation wins outright.** If `free_cancellation_hours` is set
 *    and the departure is at least that many hours away, the refund is 100% —
 *    the tiers are not consulted at all.
 * 2. **Otherwise the ladder.** `days_before` is the remaining time in whole
 *    days, rounded **down**, and the applicable tier is the one with the
 *    largest `days_before` still at or below it. No qualifying tier means 0%.
 * 3. **The base is cash actually paid**, not the booking total. A guest who
 *    paid a 30% deposit and cancels under a 50% tier is refunded half of the
 *    deposit, not half of the trip.
 *
 * ## Rounding is half-up, through `brick/money`
 *
 * CNV-4. `paid_cents * percent / 100` is fractional at almost every realistic
 * percentage, and PHP's native rounding on floats would drift. `Money::ofMinor`
 * keeps the arithmetic in minor units the whole way, so the result is an
 * integer number of cents that adds up against the payment rows.
 */
final class RefundCalculator
{
    /** CXL-3.1: a free cancellation is a full refund. */
    public const FULL_REFUND_PERCENT = 100;

    /**
     * The refund percentage for a cancellation at `$cancelledAt`.
     *
     * Both instants are UTC. `$departsAt` is `departures.starts_at_utc` — the
     * comparison is never made in local time, because a cancellation two hours
     * before a DST change would otherwise land in a different tier depending on
     * the month.
     */
    public static function percentFor(
        CancellationPolicyData $policy,
        Carbon $departsAt,
        Carbon $cancelledAt,
    ): int {
        // Cancelling after departure refunds nothing by this path. CXL-4 keeps
        // it off the guest page entirely; the operator can still record a
        // refund by hand, which is a different action with its own audit trail.
        if ($cancelledAt->greaterThanOrEqualTo($departsAt)) {
            return 0;
        }

        if (self::qualifiesForFreeCancellation($policy, $departsAt, $cancelledAt)) {
            return self::FULL_REFUND_PERCENT;
        }

        $tier = self::applicableTier($policy, self::wholeDaysBetween($departsAt, $cancelledAt));

        // CXL-3.2: "If no tier qualifies, the refund is 0%" — not the smallest
        // tier, and not an error. A ladder that starts at 15 days simply says
        // nothing about a cancellation made the day before.
        return $tier === null ? 0 : $tier->refundPercent;
    }

    /**
     * The refund in cents, from the amount actually paid (CXL-3.3).
     *
     * @param  int  $paidCents  cash received, not the booking total
     */
    public static function refundCents(
        CancellationPolicyData $policy,
        Carbon $departsAt,
        Carbon $cancelledAt,
        int $paidCents,
        string $currency = 'EUR',
    ): int {
        return self::applyPercent(
            $paidCents,
            self::percentFor($policy, $departsAt, $cancelledAt),
            $currency,
        );
    }

    /**
     * A percentage of a cash amount, rounded half up (CNV-4).
     *
     * Public because the weather workflow (CXL-6) and the operator override
     * (CXL-5) apply their own percentages to the same base, and three
     * implementations of "round half up" is three chances to disagree by a cent
     * on an invoice.
     */
    public static function applyPercent(int $paidCents, int $percent, string $currency = 'EUR'): int
    {
        if ($paidCents <= 0 || $percent <= 0) {
            return 0;
        }

        return (int) Money::ofMinor($paidCents, $currency)
            ->multipliedBy($percent / 100, RoundingMode::HALF_UP)
            ->getMinorAmount()
            ->toInt();
    }

    /** CXL-3.1, stated separately because the weather path asks the same question. */
    public static function qualifiesForFreeCancellation(
        CancellationPolicyData $policy,
        Carbon $departsAt,
        Carbon $cancelledAt,
    ): bool {
        $hours = $policy->freeCancellationHours;

        if ($hours === null) {
            return false;
        }

        // Whole hours remaining, rounded down — "at least N hours before"
        // means N hours and one minute qualifies and N hours minus one minute
        // does not. `diffInHours` truncates, which is the same rule.
        return self::wholeHoursBetween($departsAt, $cancelledAt) >= $hours;
    }

    /**
     * The tier that applies, or null when none does (CXL-3.2).
     *
     * The snapshot's tiers are already sorted descending (§3.3), so the first
     * qualifying rung is the right one — but this sorts defensively anyway,
     * because a snapshot written by an older version of the application is
     * exactly the input this has to keep working for.
     */
    public static function applicableTier(CancellationPolicyData $policy, int $daysRemaining): ?CancellationTierData
    {
        $tiers = $policy->tiers;

        usort($tiers, static fn (CancellationTierData $a, CancellationTierData $b): int => $b->daysBefore <=> $a->daysBefore);

        foreach ($tiers as $tier) {
            if ($tier->qualifiesFor($daysRemaining)) {
                return $tier;
            }
        }

        return null;
    }

    /** Whole days remaining, rounded down (CXL-3.2). */
    public static function wholeDaysBetween(Carbon $departsAt, Carbon $cancelledAt): int
    {
        return (int) floor($cancelledAt->diffInDays($departsAt, absolute: false));
    }

    /** Whole hours remaining, rounded down. */
    private static function wholeHoursBetween(Carbon $departsAt, Carbon $cancelledAt): int
    {
        return (int) floor($cancelledAt->diffInHours($departsAt, absolute: false));
    }
}
