<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Exceptions\HoldRefused;
use App\Models\Booking;
use App\Models\Departure;

/**
 * Push a hold's expiry out, or take it again if it has already gone
 * (spec AVL-39, AVL-37.5).
 *
 * The third and last permitted writer of `hold_expires_at` and
 * `departures.seats_held`.
 *
 * ## Two cases that look alike and are not
 *
 * **The hold is still alive.** Extending is free: the seats are already counted
 * against this booking, nobody else can have taken them, and the only thing
 * that changes is a timestamp. It does not need to re-check capacity, and
 * re-checking would occasionally refuse a guest their own seats.
 *
 * **The hold has expired.** The seats went back into the pool the instant it
 * did (AVL-38's read-side rule), so this is not an extension at all — it is a
 * fresh acquisition that has to compete like any other, and it can fail.
 *
 * AVL-39 is the requirement: re-entering checkout with an expired hold
 * *"re-acquires it if capacity still allows, otherwise returns `HOLD_EXPIRED`
 * with a fresh availability payload so the widget can re-render without losing
 * the guest."* Collapsing the two cases into one unconditional re-acquisition
 * would be correct and would also make a guest with twelve minutes left
 * contend for seats they already hold.
 */
final class ExtendHold
{
    public function __construct(private readonly HoldSeats $holdSeats) {}

    /**
     * @throws HoldRefused when the hold had lapsed and the seats are now gone
     */
    public function __invoke(Booking $booking, ?Departure $departure = null): Booking
    {
        if ($booking->holdsSeats()) {
            // Alive. Nothing to compete for.
            $booking->forceFill(['hold_expires_at' => HoldSeats::expiryFrom(now())])->save();

            return $booking;
        }

        $departure ??= $booking->departure_id === null
            ? null
            : Departure::query()->find($booking->departure_id);

        if ($departure === null) {
            throw HoldRefused::expiredAndUnavailable();
        }

        try {
            return ($this->holdSeats)($booking, $departure);
        } catch (HoldRefused $refused) {
            // Re-thrown as `hold_expired` rather than `not_enough_seats`,
            // because from the guest's side those are different stories: one is
            // "somebody was faster than you", the other is "your session ran out
            // while you were away". AVL-39 names the second, and the caller
            // attaches the fresh availability payload to it.
            throw $refused->reason === 'not_enough_seats'
                ? HoldRefused::expiredAndUnavailable()
                : $refused;
        }
    }
}
