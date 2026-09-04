<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Enums\BookingMode;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;

/**
 * Recompute `products.price_from_cents` (`docs/data-model.md` §1.9, spec PRC-5).
 *
 * The "from €X" on a list card. Derived and stored rather than computed on
 * read, because the `list` widget mount renders a whole catalogue and working
 * it out per card would fan out across seasons, plans and bands for every
 * product on the page.
 *
 * ## Null is a real answer and must survive
 *
 * §1.9 defines it as *"cheapest capacity-counting adult price across active rate
 * plans"*, and a product may genuinely have none: a `quote` product never shows
 * a figure, and a product with no plan at all is not sellable (PRC-5). Both
 * store **null**, and the list shows no price rather than a zero. Writing 0 for
 * "unknown" is how a free trip reaches a public page.
 *
 * ## The base band, and only if it takes a seat
 *
 * "Adult" in §1.9 means the base band — the one every multiplier is a multiple
 * of. `counts_toward_capacity` is part of the definition because a band that
 * consumes no seat can legitimately be free: an infant priced at zero is not a
 * "from" price, it is a lap.
 *
 * ## Whole-boat products quote the boat
 *
 * §1.9 is written for the per-seat case. A charter has no bands, and the honest
 * "from" figure is the vessel price — which is what a guest comparing charters
 * is reading. Saying nothing there would blank the price on every charter card.
 */
final class RecomputePriceFrom
{
    /** Recompute and persist. Returns the new value, which may be null. */
    public function __invoke(Product $product): ?int
    {
        $price = $this->cheapest($product);

        // `saveQuietly`: this runs *from* model events, and a normal save would
        // re-enter them. It also has no business bumping `updated_at` on a
        // product nobody edited.
        $product->price_from_cents = $price;
        $product->saveQuietly();

        return $price;
    }

    /** Every product whose price could have moved because `$plan` changed. */
    public function forRatePlan(RatePlan $plan): void
    {
        $product = $plan->product()->first();

        if ($product !== null) {
            $this($product);
        }
    }

    /** Same, from the band side. */
    public function forAgeBand(AgeBand $band): void
    {
        $product = $band->product()->first();

        if ($product !== null) {
            $this($product);
        }
    }

    private function cheapest(Product $product): ?int
    {
        if ($product->mode === BookingMode::Quote || ! $product->exists) {
            return null;
        }

        $planIds = RatePlan::query()
            ->where('product_id', $product->getKey())
            ->active()
            ->pluck('id');

        if ($planIds->isEmpty()) {
            return null;
        }

        if ($product->mode === BookingMode::PerVessel) {
            $cheapest = RatePlan::query()
                ->whereIn('id', $planIds)
                ->whereNotNull('vessel_price_cents')
                ->min('vessel_price_cents');

            return $cheapest === null ? null : (int) $cheapest;
        }

        $baseBandIds = AgeBand::query()
            ->where('product_id', $product->getKey())
            ->where('is_base', true)
            ->where('counts_toward_capacity', true)
            ->pluck('id');

        if ($baseBandIds->isEmpty()) {
            return null;
        }

        $cheapest = RatePlanPrice::query()
            ->whereIn('rate_plan_id', $planIds)
            ->whereIn('age_band_id', $baseBandIds)
            ->min('price_cents');

        return $cheapest === null ? null : (int) $cheapest;
    }
}
