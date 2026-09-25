<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Enums\BookingMode;
use App\Enums\DepartureStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;

/**
 * «Πραγματοποιείται με τουλάχιστον Χ άτομα» — what the guest is told about
 * `min_pax` (Mike, 2026-09-25).
 *
 * Until now the guest never saw it: the operator set a minimum, and a guest
 * learnt it existed only from the cancellation email. One definition for the
 * four places that say it (trip page, checkout, booking page, email), so they
 * cannot disagree about when it applies.
 *
 * ## Only above one, and only per seat
 *
 * `min_pax` means something only where seats are counted
 * ({@see BookingMode::usesMinPax()}). Zero is "always sails", and
 * one is the same thing in practice — the first booking guarantees it — so a
 * sentence about «τουλάχιστον 1 άτομο» would be noise.
 *
 * ## The departure's own figure where there is one
 *
 * A departure copies `min_pax` when it is generated, and
 * `CancelDeparture`/`ConfirmBooking` act on that copy. The
 * product's figure is only the fallback for the trip page, which has no
 * departure yet.
 */
final class MinimumParty
{
    /** The product's minimum, when it is worth telling the guest. */
    public static function ofProduct(Product $product): ?int
    {
        $minimum = (int) $product->min_pax;

        return $product->mode->usesMinPax() && $minimum > 1 ? $minimum : null;
    }

    /**
     * The booking's departure: guaranteed already, or the minimum it still needs.
     *
     * @return array{guaranteed: bool, minimum: int}|null
     */
    public static function ofBooking(Booking $booking): ?array
    {
        // The branding page's email preview renders an unsaved booking with no
        // mode set; it has no departure either, so there is nothing to say.
        if (! $booking->mode instanceof BookingMode || ! $booking->mode->usesMinPax()) {
            return null;
        }

        $departure = $booking->departure;

        if (! $departure instanceof Departure || (int) $departure->min_pax <= 1) {
            return null;
        }

        return [
            'guaranteed' => $departure->status === DepartureStatus::Guaranteed,
            'minimum' => (int) $departure->min_pax,
        ];
    }

    /** The minimum, only while the departure is not yet guaranteed. */
    public static function stillNeededFor(Booking $booking): ?int
    {
        $state = self::ofBooking($booking);

        return $state === null || $state['guaranteed'] ? null : $state['minimum'];
    }
}
