<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How a product is sold (`docs/data-model.md` §2.3, spec CAT-4).
 *
 * **The single most consequential column in the catalogue.** Mode decides which
 * availability service answers (#30 or #31), whether a booking consumes seats
 * or a whole boat, whether departures are generated at all, and whether a price
 * is ever shown. Nearly every rule downstream branches on it.
 *
 * `docs/data-model.md` §2.3 makes it **immutable once a booking exists**, and
 * the reason is not tidiness: a `per_seat` product that became `per_vessel`
 * would invalidate every departure and change what every existing price
 * snapshot meant. The guard is an application one, because a database cannot
 * see the bookings table from here.
 */
enum BookingMode: string
{
    use HasTranslatedLabel;

    /** Individual seats on a shared departure. Generates departures; counts seats. */
    case PerSeat = 'per_seat';

    /** The whole boat for a window. Blocks the vessel; no seat counting. */
    case PerVessel = 'per_vessel';

    /** The guest asks, the operator prices it by hand. No price is ever shown (BKG-24). */
    case Quote = 'quote';

    /** Does this mode sell seats on a generated departure? */
    public function usesDepartures(): bool
    {
        return $this === self::PerSeat;
    }

    /** Does a booking in this mode occupy the whole vessel for a window? */
    public function occupiesWholeVessel(): bool
    {
        return $this === self::PerVessel;
    }

    /**
     * May the guest propose their own start time (AVL-30)?
     *
     * CAT-5: `flexible_start` is settable on `per_vessel` alone. A shared
     * departure has one start time by definition — a guest choosing their own
     * would be a different departure.
     */
    public function allowsFlexibleStart(): bool
    {
        return $this === self::PerVessel;
    }

    /**
     * Does `min_pax` mean anything here?
     *
     * CAT-5: it is the guaranteed-departure threshold, which only exists when
     * seats are counted. On a whole-boat charter the party size is whatever the
     * charterer brings.
     */
    public function usesMinPax(): bool
    {
        return $this === self::PerSeat;
    }

    /** Is a price shown to the guest before they commit? */
    public function showsPrice(): bool
    {
        return $this !== self::Quote;
    }
}
