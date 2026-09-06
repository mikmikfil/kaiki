<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Booking\Support\BookingHoldSource;
use App\Domain\Booking\Support\BookingProductCount;
use App\Domain\Catalog\Actions\SaveProduct;
use Illuminate\Support\ServiceProvider;

/**
 * The four seams M1 left open, closed (spec AVL-25, AVL-33, AVL-38).
 *
 * M1 shipped four interfaces with **no implementations registered**, each with
 * a docblock saying the same thing: the rule, its error code and its tests ship
 * now, and M2 adds one class and one `tag()` line. `bookings` did not exist, so
 * every one of them correctly reported nothing.
 *
 * This is that line, four times. The classes are in `app/Domain/Booking`,
 * because the availability engine asks the questions and must not know that
 * `bookings` is where the answers come from.
 *
 * | Tag | Answers | Silent failure without it |
 * |---|---|---|
 * | `availability.persons-aboard` | AVL-25's legal head-count | a boat sails full of infants |
 * | `availability.vessel-holds` | AVL-3.4's expiring occupation | two guests check out for the same boat |
 * | `availability.expired-holds` | AVL-38's read-side release | a queue backlog costs bookings |
 * | `product.booking-counts` | can a product's mode still change | a mode change reinterprets a live booking |
 *
 * Every one of those failures is quiet. Nothing throws, nothing logs, and the
 * numbers stay plausible — which is why they are wired here as a group rather
 * than one at a time as each is first needed.
 *
 * `GuardVesselCapacity::TAG` is deliberately **not** filled here. It asks what
 * has been promised against a *vessel's* capacity, which `products.max_pax` and
 * `departures.capacity` answer; it predates bookings and is not this issue's.
 */
class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One class implements the three availability contracts, because they
        // are one question asked three ways and three files that must agree
        // about "an unexpired hold" are three chances to disagree.
        $this->app->singleton(BookingHoldSource::class);
        $this->app->singleton(BookingProductCount::class);

        $this->app->tag([BookingHoldSource::class], CheckSeatAvailability::TAG);
        $this->app->tag([BookingHoldSource::class], CheckSeatAvailability::EXPIRED_HOLDS_TAG);
        $this->app->tag([BookingHoldSource::class], OccupationCollector::HOLD_SOURCE_TAG);
        $this->app->tag([BookingProductCount::class], SaveProduct::TAG);
    }
}
