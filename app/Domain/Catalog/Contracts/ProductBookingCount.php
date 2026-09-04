<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Contracts;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Models\Product;

/**
 * Something that can say how many commitments a product already carries.
 *
 * Exists so {@see SaveProduct} can enforce
 * `docs/data-model.md` §2.3's *"mode is immutable after the first booking
 * exists"* **before `bookings` exists**. There is deliberately no
 * implementation registered today: nothing can hold a booking until M2, so the
 * guard correctly refuses nothing, and the refusal, its localised message and
 * its tests are all built and proven against a fake source.
 *
 * M2 adds one class implementing this and one `tag()` line in
 * `AppServiceProvider`. Same shape as {@see VesselCapacityClaims} from #16, and
 * for the same reason: the alternative is writing this guard during the largest
 * milestone in the project, at the moment it is most tempting to skip.
 */
interface ProductBookingCount
{
    /** How many bookings reference this product, in any committed state. */
    public function countFor(Product $product): int;
}
