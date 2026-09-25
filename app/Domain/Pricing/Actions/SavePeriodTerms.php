<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Models\RatePlan;
use Illuminate\Validation\ValidationException;

/**
 * One period's deposit and deadlines, when they differ from the trip's
 * (product owner, 2026-09-24: the «⋯» on a period's column).
 *
 * `$terms` null puts the period back on the trip's terms: they are copied from
 * the year-round plan and the period follows them from then on. Anything else
 * is the period's own, and it stops following. Either way the plan goes through
 * {@see SaveRatePlan}, so a deposit of 0% is refused here as it is everywhere.
 */
final class SavePeriodTerms
{
    public function __construct(private readonly SaveRatePlan $saveRatePlan) {}

    /**
     * @param  array<string, mixed>|null  $terms
     *
     * @throws ValidationException
     */
    public function __invoke(RatePlan $plan, ?array $terms): RatePlan
    {
        $product = $plan->product()->firstOrFail();

        if ($terms === null) {
            $default = RatePlan::query()
                ->where('product_id', $product->getKey())
                ->whereNull('season_id')
                ->first();

            $terms = $default instanceof RatePlan ? SavePriceTable::termsOf($default) : SavePriceTable::noTerms();
            $follows = true;
        } else {
            // The balance deadline has no field in the modal, so a caller that
            // does not send it keeps the plan's own rather than wiping it
            // (2026-09-25). Every other term is on the form and sent.
            $terms = array_intersect_key($terms, array_flip(SavePriceTable::TERMS))
                + ['balance_due_days_before_departure' => $plan->balance_due_days_before_departure]
                + SavePriceTable::noTerms();
            $follows = false;
        }

        return $this->saveRatePlan->__invoke($plan, $product, $terms + [
            'season_id' => $plan->season_id,
            'follows_trip_terms' => $follows,
        ]);
    }
}
