<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\Support\HoldLock;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Departure;
use Illuminate\Support\Facades\DB;

/**
 * Give the seats back (spec AVL-38, AVL-37.5, ADR-0005).
 *
 * One of the three permitted writers of `hold_expires_at` and
 * `departures.seats_held`. Called on all four of AVL-38's release conditions —
 * payment failure, explicit abandonment, expiry, and successful confirmation,
 * where the seats are not returned but *converted* and the caller moves them
 * into `seats_sold` in the same transaction.
 *
 * ## Idempotent, because every caller is a retry waiting to happen
 *
 * A queued job that runs twice, a guest who clicks abandon twice, a sweeper
 * that overlaps its own previous run. Releasing a hold that is already released
 * decrements nothing and returns quietly, because the counter is recomputed
 * from live holds rather than decremented — the same self-healing arithmetic
 * {@see HoldSeats} uses, and the reason a double release cannot drive
 * `seats_held` negative.
 *
 * ## The counter is recomputed, never decremented
 *
 * A decrement is only correct if every increment was: it carries forward
 * whatever drift the counter has accumulated, and drift in `seats_held` is
 * either seats nobody can buy or seats sold twice. Recounting costs one indexed
 * query and is right whatever happened before it.
 */
final class ReleaseHold
{
    public function __invoke(Booking $booking): Booking
    {
        if ($booking->hold_expires_at === null) {
            return $booking;
        }

        $departure = $booking->departure_id === null
            ? null
            : Departure::query()->find($booking->departure_id);

        if ($departure === null) {
            // A per-vessel hold, or a departure that has since gone. The hold
            // is still cleared: a `hold_expires_at` with nothing to release is
            // a row the sweeper picks up forever.
            $booking->forceFill(['hold_expires_at' => null])->save();

            return $booking;
        }

        return HoldLock::run(HoldLock::forDeparture($departure->getKey()), function () use ($booking, $departure): Booking {
            return DB::transaction(function () use ($booking, $departure): Booking {
                $booking->forceFill(['hold_expires_at' => null])->save();

                /** @var Departure $locked */
                $locked = Departure::query()->lockForUpdate()->findOrFail($departure->getKey());

                $locked->forceFill(['seats_held' => self::liveHeldSeats($locked)])->save();

                return $booking;
            });
        });
    }

    /**
     * Seats genuinely held on a departure right now.
     *
     * Shared with the sweeper, which recomputes the same figure for a batch.
     * Both read the truth from `bookings`; `departures.seats_held` is a cache
     * of it, and treating it as the source is what lets drift accumulate.
     */
    public static function liveHeldSeats(Departure $departure): int
    {
        return (int) Booking::query()
            ->where('departure_id', $departure->getKey())
            ->where('status', BookingStatus::Draft->value)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '>', now())
            ->sum('pax_capacity_total');
    }
}
