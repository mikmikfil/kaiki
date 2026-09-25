<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Availability\Support\OccupationCollector;
use App\Domain\Availability\Support\Window;
use App\Enums\BookingMode;
use App\Models\Booking;
use App\Models\Vessel;

/**
 * Does anybody **else** have the boat for this charter's window (2026-09-25)?
 *
 * The question `StartCheckout` and `ConfirmBooking` ask of a private charter,
 * under the vessel lock they already hold. Through {@see OccupationCollector},
 * like the calendar and `AcceptQuote`, so there is one answer to "is this boat
 * busy" — with the booking's own hold left out, since it occupies exactly the
 * window being asked about.
 *
 * Takes no lock itself: `LockDisciplineTest` reads each Action for its locks in
 * AVL-45's order, and a lock hidden in a helper is one it cannot see.
 */
final class CharterOccupancy
{
    /** True for anything that is not a charter: there is no boat to ask about. */
    public static function isFreeFor(Vessel $vessel, Booking $booking): bool
    {
        if ($booking->mode === BookingMode::PerSeat || $booking->departure_id !== null) {
            return true;
        }

        $window = Window::of($booking->starts_at_utc, $booking->ends_at_utc);

        return OccupationCollector::forRange($vessel, $window, (int) $booking->getKey())->isFree($window);
    }
}
