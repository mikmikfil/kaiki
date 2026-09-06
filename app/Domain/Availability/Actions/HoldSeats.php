<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\Support\HoldLock;
use App\Enums\BookingStatus;
use App\Exceptions\HoldLockUnavailable;
use App\Exceptions\HoldRefused;
use App\Models\Booking;
use App\Models\Departure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Take the hold for a draft booking (spec AVL-37, AVL-38, ADR-0005 Option A).
 *
 * ## One of exactly three writers
 *
 * AVL-37.5: `HoldSeats`, {@see ExtendHold} and {@see ReleaseHold} are the
 * **only** writers of `hold_expires_at` and `departures.seats_held`, and
 * `SingleHoldWriterTest` scans the source to keep that true. The rule is not
 * tidiness — a second writer is what makes a counter untrustworthy, and an
 * untrustworthy `seats_held` is an oversell nobody can explain afterwards.
 *
 * ## Two locks, doing two different jobs
 *
 * AVL-37.4 asks for both and they are not redundant:
 *
 * - `Cache::lock` ({@see HoldLock}) serialises *hold creation* across
 *   processes. It is a mutex around the read-check-write, and without it two
 *   requests both read "three seats free" and both take two.
 * - `lockForUpdate()` inside the transaction takes the **row** lock AVL-43
 *   describes, so the counter write is protected by the database as well.
 *
 * On SQLite the row lock is a no-op (ADR-0006) and the cache lock still works,
 * which is why the durability tests run locally and the true-parallelism ones
 * are tagged `mysql`.
 *
 * ## Expired holds are counted out before capacity is judged
 *
 * AVL-38's read-side rule, applied here too. The stored `seats_held` may
 * include holds that ran out a minute ago and the sweeper has not reached; a
 * guest refused a seat that is actually free would be refused by a queue
 * backlog rather than by the boat being full. So the critical section recounts
 * live holds from `bookings` and writes the true figure back — which makes this
 * Action self-healing, and means a sweeper outage degrades throughput rather
 * than correctness.
 *
 * ## Never for `quote` mode
 *
 * AVL-41. A quote holds nothing, because there is no price yet and therefore
 * nothing the guest has committed to. The caller checks the mode; this Action
 * refuses one that reaches it anyway, because a rule enforced only at the call
 * site is a rule the second call site forgets.
 */
final class HoldSeats
{
    /**
     * @param  Booking  $booking  a saved `draft` whose `pax_capacity_total` is set
     *
     * @throws HoldRefused when the departure cannot fit the party
     * @throws HoldLockUnavailable when another writer will not yield
     */
    public function __invoke(Booking $booking, Departure $departure): Booking
    {
        if ($booking->mode->value === 'quote') {
            throw HoldRefused::quoteModeHoldsNothing();
        }

        $seats = $booking->pax_capacity_total;

        if ($seats < 1) {
            // A hold of zero seats is not a hold, and writing one would leave a
            // `hold_expires_at` the sweeper has to reason about for a booking
            // that occupies nothing.
            throw HoldRefused::nothingToHold();
        }

        return HoldLock::run(HoldLock::forDeparture($departure->getKey()), function () use ($booking, $departure, $seats): Booking {
            return DB::transaction(function () use ($booking, $departure, $seats): Booking {
                /** @var Departure $locked */
                $locked = Departure::query()->lockForUpdate()->findOrFail($departure->getKey());

                $liveHeld = $this->liveHeldSeats($locked, exceptBooking: $booking->getKey());
                $free = $locked->capacity - $locked->seats_sold - $liveHeld;

                if ($free < $seats) {
                    throw HoldRefused::notEnoughSeats($seats, max(0, $free));
                }

                $expiresAt = self::expiryFrom(now());

                $booking->forceFill(['hold_expires_at' => $expiresAt])->save();

                // Written from the recount plus this booking, not incremented:
                // an increment carries forward whatever drift the counter had.
                $locked->forceFill(['seats_held' => $liveHeld + $seats])->save();

                return $booking;
            });
        });
    }

    /** When a hold taken now runs out. */
    public static function expiryFrom(Carbon $now): Carbon
    {
        return $now->copy()->addMinutes((int) config('kaiki.booking.hold_minutes'));
    }

    /**
     * Seats genuinely held on this departure right now.
     *
     * Recounted from `bookings` rather than read from the counter, so an
     * expired hold the sweeper has not reached is not charged against a guest
     * who is here now. `$exceptBooking` excludes the booking being re-held, so
     * extending or re-acquiring a hold does not count its own seats twice.
     */
    private function liveHeldSeats(Departure $departure, ?int $exceptBooking = null): int
    {
        return (int) Booking::query()
            ->where('departure_id', $departure->getKey())
            ->where('status', BookingStatus::Draft->value)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '>', now())
            ->when($exceptBooking !== null, fn ($query) => $query->whereKeyNot($exceptBooking))
            ->sum('pax_capacity_total');
    }
}
