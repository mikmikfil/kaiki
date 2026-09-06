<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Models\Booking;
use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * When crew may tick somebody aboard (spec BKG-22, AVL-5).
 *
 * > **BKG-22** Check-in is possible from `check_in_offset_minutes` before
 * > departure until `ends_at_utc`; earlier check-in requires an explicit
 * > operator override which is logged.
 *
 * ## Two boundaries that behave differently, and that is the requirement
 *
 * The **early** edge is soft: an operator may push past it, with a reason, and
 * the push is written to the trail. The **late** edge is hard — there is no
 * override past `ends_at_utc`, because a check-in recorded after the boat came
 * back is not an early decision, it is a false manifest. BKG-22 offers an
 * override for one edge and not the other, and the asymmetry is deliberate.
 *
 * ## The offset is the product's, not the vessel's or the departure's
 *
 * CAT-4 puts `check_in_offset_minutes` on the Product, and AVL-5 is explicit
 * that it *"affects the guest-facing check-in time only; it does **not** extend
 * the occupation"*. So this reads the product and nothing here touches
 * availability arithmetic — a sunset cruise asking guests to arrive an hour
 * early does not thereby block the vessel an hour longer.
 *
 * ## Everything is UTC, and the panel converts
 *
 * CNV-2. A window computed in local time is a window that is wrong twice a year
 * — and the last Sunday in October is inside the Greek season, so "twice a
 * year" means "on a day boats are sailing". The booking already carries
 * `starts_at_utc` and `ends_at_utc`, which are the authoritative pair
 * (`LocalDateTimeResolver` owns the conversion); nothing here recomputes them.
 */
final readonly class CheckInWindow
{
    private function __construct(
        public Carbon $opensAt,
        public Carbon $closesAt,
    ) {}

    /**
     * The window for a booking.
     *
     * Falls back to the schema default of thirty minutes when the product is
     * not loaded rather than lazily loading it: this is called once per row
     * while rendering a pax list, and an N+1 on a boat with fourteen guests is
     * fourteen queries a crew member waits for on a pier with one bar of
     * signal.
     */
    public static function forBooking(Booking $booking, ?Product $product = null): self
    {
        $product ??= $booking->relationLoaded('product') ? $booking->product : null;

        $offset = $product instanceof Product
            ? $product->check_in_offset_minutes
            : Product::DEFAULT_CHECK_IN_OFFSET_MINUTES;

        return new self(
            opensAt: $booking->starts_at_utc->copy()->subMinutes($offset),
            closesAt: $booking->ends_at_utc->copy(),
        );
    }

    /** Is `$at` inside the window? */
    public function isOpen(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        return $at->greaterThanOrEqualTo($this->opensAt)
            && $at->lessThanOrEqualTo($this->closesAt);
    }

    /**
     * Is `$at` before the window — the case an override can rescue?
     *
     * A guest who turned up two hours early for a boat that has not arrived is
     * a real morning on a quay, and BKG-22 lets crew act on it. This is the
     * only "no" that has a way through.
     */
    public function isEarly(?Carbon $at = null): bool
    {
        return ($at ?? Carbon::now())->lessThan($this->opensAt);
    }

    /**
     * Is `$at` after the boat came back?
     *
     * No override reaches this. See the class docblock.
     */
    public function hasClosed(?Carbon $at = null): bool
    {
        return ($at ?? Carbon::now())->greaterThan($this->closesAt);
    }
}
