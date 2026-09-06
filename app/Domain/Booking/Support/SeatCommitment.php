<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Availability\Actions\ReleaseHold;
use App\Models\Departure;
use Illuminate\Support\Facades\DB;

/**
 * The conditional counter update (spec AVL-43.2, ADR-0006 Option A).
 *
 * ## Why this is not `$departure->increment('seats_sold', $n)`
 *
 * Because an increment always succeeds. It writes the number whether or not the
 * seats were there, and the boat is oversold with nothing in any log — which is
 * a guest standing on a quay holding a ticket for a seat that does not exist.
 *
 * AVL-43.2 specifies the shape exactly:
 *
 * ```sql
 * UPDATE departures
 *    SET seats_sold = seats_sold + :n
 *  WHERE id = :id
 *    AND capacity - seats_sold - seats_held >= :n
 * ```
 *
 * The `WHERE` clause is the guarantee. Two transactions that both read "one
 * seat left" cannot both write: the second one's condition is evaluated against
 * the row **as it is at write time**, and zero affected rows is the answer.
 *
 * ## It works on SQLite, which is the point
 *
 * `SELECT … FOR UPDATE` is a no-op on SQLite (ADR-0006), so the row lock proves
 * nothing locally and the concurrency test is `@group mysql`. This guard is
 * portable: it runs on every driver, and `ConditionalCounterTest` exercises the
 * refusal on SQLite with no parallelism at all, by writing the row from
 * underneath a stale read. **The invariant is therefore tested locally even
 * though the race is not.**
 *
 * ## Held seats are subtracted, and expired holds are not
 *
 * `capacity − seats_sold − seats_held` is the arithmetic the requirement gives.
 * A hold that has already lapsed is not a seat anybody has, so the caller
 * releases it before committing — {@see ReleaseHold}
 * recomputes the counter from live holds, which is what makes this subtraction
 * true rather than merely conservative.
 */
final class SeatCommitment
{
    /**
     * Move `$seats` into `seats_sold`, or refuse.
     *
     * @param  int  $ownHeldSeats  seats this booking already holds, which are
     *                             about to move rather than being competed for
     * @return bool false when the seats were not there — the caller aborts the
     *              transaction with `CAPACITY_EXCEEDED`
     */
    public static function commit(Departure $departure, int $seats, int $ownHeldSeats = 0): bool
    {
        if ($seats < 1) {
            return true;
        }

        // `seats_held` is reduced by this booking's own hold in the same
        // statement, because those seats are **moving** from held to sold
        // (BKG-9) rather than being taken again. Without this a guest is
        // refused their own seats: counted once in `seats_held` and once more
        // in the `:n` being requested.
        //
        // **The credit is clamped to what the counter actually holds**, and
        // that clamp is the difference between a correct statement and an
        // oversell vector. A booking whose `hold_expires_at` is still set but
        // whose seats have already been released — a sweeper that got there
        // first, a hold released by hand — would otherwise subtract a hold that
        // is not in the counter, *manufacturing* capacity out of arithmetic.
        // A test caught exactly that: a departure with one seat, one seat sold
        // and a booking still claiming a hold was allowed to confirm.
        //
        // `CASE WHEN`, not `LEAST()` or `MIN()`: MySQL has `LEAST`, SQLite has
        // a two-argument `MIN`, and neither has the other. This is the one form
        // both understand.
        $credit = 'CASE WHEN seats_held < ' . $ownHeldSeats . ' THEN seats_held ELSE ' . $ownHeldSeats . ' END';

        $affected = DB::table('departures')
            ->where('id', $departure->getKey())
            ->whereRaw('capacity - seats_sold - (seats_held - (' . $credit . ')) >= ?', [$seats])
            ->update([
                'seats_sold' => DB::raw('seats_sold + ' . $seats),
                'seats_held' => DB::raw('seats_held - (' . $credit . ')'),
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * Give committed seats back, on cancellation.
     *
     * Unconditional, unlike the commit: seats returning to the pool can never
     * fail, and a `WHERE` clause here would leave a cancelled booking's seats
     * off sale forever if it did not match. Floored at zero in SQL rather than
     * in PHP, so two concurrent cancellations cannot drive the counter negative
     * between a read and a write.
     */
    public static function release(Departure $departure, int $seats): void
    {
        if ($seats < 1) {
            return;
        }

        DB::table('departures')
            ->where('id', $departure->getKey())
            ->update([
                'seats_sold' => DB::raw('CASE WHEN seats_sold >= ' . $seats
                    . ' THEN seats_sold - ' . $seats . ' ELSE 0 END'),
                'updated_at' => now(),
            ]);
    }
}
