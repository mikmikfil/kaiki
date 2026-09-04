<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Pricing\Actions\RecomputePriceFrom;
use App\Models\AgeBand;

/**
 * Which band is the base decides which price is the "from" price (§1.9).
 *
 * An operator moving the base flag from Adult to Child changes the figure on
 * every card without touching a single price, which is exactly the kind of
 * change nobody thinks to recompute after.
 */
final class AgeBandObserver
{
    public function saved(AgeBand $band): void
    {
        app(RecomputePriceFrom::class)->forAgeBand($band);
    }

    public function deleted(AgeBand $band): void
    {
        app(RecomputePriceFrom::class)->forAgeBand($band);
    }

    /**
     * `SaveAgeBands` rewrites the whole set with `forceDelete()`, which does not
     * fire `deleted` on a soft-deleting model. Without this, replacing the base
     * band leaves the old "from" price on every card.
     */
    public function forceDeleted(AgeBand $band): void
    {
        app(RecomputePriceFrom::class)->forAgeBand($band);
    }
}
