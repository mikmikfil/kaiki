<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Pricing\Actions\RecomputePriceFrom;
use App\Models\RatePlanPrice;

/**
 * The band price is the number `price_from_cents` actually reads.
 *
 * `SaveRatePlan` deletes and rewrites these rows without touching the plan, so
 * watching the plan alone would leave the "from" figure at yesterday's price
 * every time an operator only changed the amounts.
 */
final class RatePlanPriceObserver
{
    public function saved(RatePlanPrice $price): void
    {
        $this->recompute($price);
    }

    public function deleted(RatePlanPrice $price): void
    {
        $this->recompute($price);
    }

    private function recompute(RatePlanPrice $price): void
    {
        $plan = $price->ratePlan()->first();

        if ($plan !== null) {
            app(RecomputePriceFrom::class)->forRatePlan($plan);
        }
    }
}
