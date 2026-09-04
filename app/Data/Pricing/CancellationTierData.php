<?php

declare(strict_types=1);

namespace App\Data\Pricing;

use Spatie\LaravelData\Data;

/**
 * One rung of the refund ladder (`docs/data-model.md` §3.3, spec CXL-3).
 *
 * "Cancel at least `daysBefore` days ahead and you are refunded
 * `refundPercent`." A **threshold, not a bucket** — evaluation takes the tier
 * with the largest `daysBefore` that is still less than or equal to the days
 * actually remaining, which is why a ladder with gaps behaves sensibly and why
 * two tiers at the same threshold are refused by a unique index.
 */
final class CancellationTierData extends Data
{
    public function __construct(
        public readonly int $daysBefore,
        public readonly int $refundPercent,
    ) {}

    /** Does this tier apply to a cancellation with `$daysRemaining` to go? */
    public function qualifiesFor(int $daysRemaining): bool
    {
        return $this->daysBefore <= $daysRemaining;
    }
}
