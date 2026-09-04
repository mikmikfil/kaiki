<?php

declare(strict_types=1);

namespace App\Data\Pricing;

use App\Models\RatePlan;
use App\Models\Season;
use LogicException;
use Spatie\LaravelData\Data;

/**
 * Which rate plan prices a product on a date, or the fact that none does
 * (spec PRC-5).
 *
 * ## "Not sellable" is a value, not an exception and not a zero
 *
 * PRC-5 is explicit: with no seasonal plan and no default plan, the product is
 * **not sellable for that date**, availability omits it, and the operator gets
 * a panel warning. The two shapes that would be easier are both wrong:
 *
 * - An exception makes the ordinary case — a 62-day availability response where
 *   three dates fall outside every season — a control-flow event, and something
 *   downstream eventually catches it and substitutes a zero.
 * - A zero price is a free trip on a public booking page.
 *
 * So the caller is handed an object that says `no` and has to look, which is
 * what {@see self::isSellable()} is for.
 */
final class ResolvedRatePlanData extends Data
{
    public function __construct(
        public readonly ?RatePlan $plan,
        public readonly ?Season $season,
    ) {}

    /** A plan applies. `season` is null when the winner is the product default. */
    public static function sellable(RatePlan $plan, ?Season $season): self
    {
        return new self($plan, $season);
    }

    /** No plan applies, and no price may be shown. */
    public static function notSellable(): self
    {
        return new self(null, null);
    }

    public function isSellable(): bool
    {
        return $this->plan !== null;
    }

    /** Did the product default win, rather than a named season? */
    public function usedDefaultPlan(): bool
    {
        return $this->plan !== null && $this->season === null;
    }

    /**
     * The plan, for a caller that has already checked.
     *
     * Throws rather than returning null, so a caller that skipped
     * {@see self::isSellable()} fails loudly here instead of quietly pricing
     * something at nothing further down.
     */
    public function planOrFail(): RatePlan
    {
        return $this->plan ?? throw new LogicException('No rate plan resolved.');
    }
}
