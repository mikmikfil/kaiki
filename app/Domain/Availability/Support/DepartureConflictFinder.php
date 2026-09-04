<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\VesselCalendar;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Vessel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a proposed departure would clash with (spec AVL-11, AVL-12).
 *
 * A thin reader over {@see VesselCalendar}, which is the only class permitted
 * to query vessel occupancy (AVL-12a). It exists so the panel has something to
 * ask a question of without knowing what a `Window` is, and so the same answer
 * reaches the generator later.
 *
 * ## Two different clashes, two different consequences
 *
 * - **A different product** on the same boat is a **warning**. AVL-11 is
 *   explicit that overlapping zero-sold departures may coexist: operators
 *   schedule two products on one boat at the same hour and let the bookings
 *   decide. Blocking that would be wrong.
 * - **The same product** at an overlapping time is **refused**. AVL-12's last
 *   sentence: *"Same-product overlap is additionally blocked by validation at
 *   creation time."* Two departures of one product on one boat are the same
 *   trip sold twice, which is a mistake rather than a strategy.
 */
final class DepartureConflictFinder
{
    /**
     * Departures the proposed window would clash with (AVL-12: any other one).
     *
     * @return Collection<int, Departure>
     */
    public static function for(Vessel $vessel, Window $window, ?Departure $excluding = null): Collection
    {
        return VesselCalendar::conflictingDepartures($vessel, $window, $excluding);
    }

    /**
     * The same question from a product, a local date and a local time.
     *
     * The window is built through {@see LocalDateTimeResolver} rather than from
     * the parts, because AVL-16 allows exactly one conversion and a panel
     * warning computed from a second one would disagree with the row it warns
     * about across a DST boundary.
     *
     * @return Collection<int, Departure>
     */
    public static function forProposed(
        Product $product,
        Vessel $vessel,
        Carbon|string $localDate,
        string $localTime,
        ?Departure $excluding = null,
    ): Collection {
        $window = self::windowFor($product, $localDate, $localTime);

        return $window === null ? collect() : self::for($vessel, $window, $excluding);
    }

    /**
     * The UTC window a proposed departure would occupy.
     *
     * Null when the local time does not exist on that date (ADR-0016) — there
     * is nothing to conflict with a departure that cannot be created.
     */
    public static function windowFor(Product $product, Carbon|string $localDate, string $localTime): ?Window
    {
        $resolved = LocalDateTimeResolver::resolve($localDate, $localTime, LocalDateTimeResolver::timezone());

        if (! $resolved->existent) {
            return null;
        }

        $starts = $resolved->instantOrFail();

        return Window::of($starts, LocalDateTimeResolver::endsAt($starts, (int) $product->duration_minutes));
    }
}
