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
    /**
     * @param  bool  $allowOvercapacity  BKG-32's operator override, added by #89
     */
    public function __invoke(Booking $booking, Departure $departure, bool $allowOvercapacity = false): Booking
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

        return HoldLock::run(HoldLock::forDeparture($departure->getKey()), function () use ($booking, $departure, $seats, $allowOvercapacity): Booking {
            return DB::transaction(function () use ($booking, $departure, $seats, $allowOvercapacity): Booking {
                /** @var Departure $locked */
                $locked = Departure::query()->lockForUpdate()->findOrFail($departure->getKey());

                $liveHeld = $this->liveHeldSeats($locked, exceptBooking: $booking->getKey());
                $free = $locked->capacity - $locked->seats_sold - $liveHeld;

                // BKG-32's override (#89), and the reason it is a *parameter*
                // rather than a second Action: the seat arithmetic, the lock
                // and the counter write are identical, and only the refusal
                // differs. A duplicate hold path would be a second writer of
                // `seats_held`, which `NoDirectRedisTest` and every invariant
                // in `CLAUDE.md` exist to prevent.
                //
                // It lifts the **commercial** ceiling — the departure's own
                // `capacity`, a number the operator chose — and touches nothing
                // else. The legal `capacity_max` (AVL-25) is checked below and
                // has no override at all: an operator may squeeze one more
                // person onto a boat they under-sold, and may not sail illegally
                // full. That is the only reading in which both of BKG-32's
                // sentences are true.
                if ($free < $seats && ! $allowOvercapacity) {
                    throw HoldRefused::notEnoughSeats($seats, max(0, $free));
                }

                if ($allowOvercapacity && $this->wouldSailIllegallyFull($booking, $locked, $seats)) {
                    throw HoldRefused::legalCapacityExceeded();
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

    /**
     * AVL-25, the check no override reaches.
     *
     * *"`total_persons_on_board <= vessel.capacity_max`, where
     * `total_persons_on_board` counts **every** person including age bands with
     * `counts_toward_capacity = false`"* — the infants a commercial capacity
     * deliberately does not count are exactly the ones a coastguard does.
     *
     * Only consulted on the override path, because the ordinary path never
     * reaches a capacity the calendar and {@see PartyGuard} have not already
     * cleared.
     */
    private function wouldSailIllegallyFull(Booking $booking, Departure $departure, int $seats): bool
    {
        $ceiling = $departure->vessel?->capacity_max;

        if ($ceiling === null) {
            return false;
        }

        $aboard = (int) Booking::query()
            ->where('departure_id', $departure->getKey())
            ->whereKeyNot($booking->getKey())
            ->whereIn('status', array_values(array_map(
                static fn (BookingStatus $status): string => $status->value,
                array_filter(
                    BookingStatus::cases(),
                    static fn (BookingStatus $status): bool => $status->committingSeats(),
                ),
            )))
            ->sum('pax_total');

        return $aboard + $booking->pax_total > $ceiling;
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
