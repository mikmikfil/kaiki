<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Contracts;

use App\Domain\Catalog\Actions\GuardVesselCapacity;
use App\Domain\Catalog\Data\CapacityClaim;
use App\Models\Vessel;

/**
 * Something that has already promised seats on a vessel.
 *
 * `vessels.capacity_max` is the **legal** ceiling (`docs/data-model.md` §2.3),
 * and lowering it below a promise already made would either oversell a boat or
 * silently invalidate a sold departure. The database cannot express the
 * constraint — it spans three tables — so {@see GuardVesselCapacity} enforces
 * it, and this interface is how it finds out what has been promised.
 *
 * ## Why an interface rather than two queries
 *
 * The promises live in tables that do not exist yet: `products.max_pax` arrives
 * with #18 and `departures.capacity` with #23. Writing the guard against those
 * tables today would mean writing it twice, or writing nothing and discovering
 * on the day `products` lands that the acceptance criterion for **this** issue
 * was never met.
 *
 * So the mechanism ships now with **no implementations registered**, which
 * means the guard currently refuses nothing — correctly, because nothing can
 * yet claim a seat. #18 and #23 each add one implementation and one line in
 * `AppServiceProvider`, and the message, the localisation and both enforcement
 * points are already proven by then.
 *
 * ## Contract
 *
 * An implementation is called inside the save path, so it must be cheap and it
 * must not write. It sees the vessel **before** the new capacity is applied;
 * the proposed value arrives as `$newCapacity`.
 */
interface VesselCapacityClaims
{
    /**
     * Records on this vessel that promise more pax than `$newCapacity`.
     *
     * Returns an empty list when nothing objects — which is the answer for
     * every source most of the time, and the only answer available today.
     *
     * @return list<CapacityClaim>
     */
    public function exceeding(Vessel $vessel, int $newCapacity): array;
}
