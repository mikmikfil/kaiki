<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Pricing\Actions\RecomputePriceFrom;
use App\Models\RatePlan;

/**
 * Keeps `products.price_from_cents` true after a plan changes (§1.9).
 *
 * On the model rather than in a provider, so an import, a console command or a
 * seeder cannot write a plan that leaves a stale "from €X" on a public card —
 * the panel is not the only writer, and the stale figure is the one a guest
 * reads before deciding to click.
 */
final class RatePlanObserver
{
    public function saved(RatePlan $plan): void
    {
        app(RecomputePriceFrom::class)->forRatePlan($plan);
    }

    public function deleted(RatePlan $plan): void
    {
        app(RecomputePriceFrom::class)->forRatePlan($plan);
    }

    public function restored(RatePlan $plan): void
    {
        app(RecomputePriceFrom::class)->forRatePlan($plan);
    }
}
