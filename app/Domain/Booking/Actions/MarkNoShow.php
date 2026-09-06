<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Models\Booking;
use App\Models\BookingGuest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Somebody did not turn up (spec BKG-23).
 *
 * > **BKG-23** No-show marking is available **per guest and per booking**, is
 * > **reversible**, and **does not itself trigger any refund logic**.
 *
 * ## The last clause is the entire design
 *
 * Marking a no-show is a **record**, not a decision. It writes one boolean and
 * does nothing else: no refund, no cancellation, no seat released, no status
 * change, no event. That reads like an omission and is the requirement — the
 * money question is settled by `cancellation_policies.no_show_refund_percent`
 * at the moment somebody actually refunds, from the **policy snapshot frozen at
 * booking** (CXL-1), and an action that also moved money would apply today's
 * policy to a booking made under last season's.
 *
 * It is also why nothing here is audited. An `override.applied` row records a
 * decision that overruled a rule; a no-show mark overrules nothing, is
 * reversible with one click, and would fill the trail with rows nobody consults
 * — ADR-0025 §2 is explicit that the trail is for *"destructive actions and
 * operator overrides"*.
 *
 * ## Per guest and per booking are separate facts
 *
 * Not a derived one. A family of four where one person missed the boat is
 * **three people who sailed** — rolling the guest marks up into the booking
 * would make the manifest, the operator's numbers and eventually the invoice
 * all say the trip did not happen for anybody.
 *
 * {@see self::forBooking()} therefore marks the booking *and* every guest on
 * it, which is the "nobody came" case an operator wants in one click; the
 * per-guest call leaves the booking alone.
 *
 * ## Reversal needs no second concept
 *
 * `$flag = false` on the same method, rather than an `UndoNoShow` action.
 * BKG-23 calls it reversible and not "cancellable" — there is no state to
 * unwind and nothing downstream to compensate, because nothing downstream ever
 * happened.
 */
final class MarkNoShow
{
    /** One person. The booking's own flag is left alone — see the class docblock. */
    public function forGuest(BookingGuest $guest, bool $flag = true): void
    {
        DB::table('booking_guests')
            ->where('id', $guest->getKey())
            ->update(['no_show' => $flag, 'updated_at' => Carbon::now()]);

        $guest->refresh();
    }

    /**
     * Nobody came.
     *
     * The booking and every guest on it, in one transaction so the two halves
     * cannot disagree — a booking flagged as a no-show whose guests are not is
     * a manifest that contradicts itself, and the manifest is what a coastguard
     * inspection reads.
     */
    public function forBooking(Booking $booking, bool $flag = true): void
    {
        DB::transaction(static function () use ($booking, $flag): void {
            $now = Carbon::now();

            DB::table('bookings')
                ->where('id', $booking->getKey())
                ->update(['no_show' => $flag, 'updated_at' => $now]);

            DB::table('booking_guests')
                ->where('booking_id', $booking->getKey())
                ->update(['no_show' => $flag, 'updated_at' => $now]);
        });

        $booking->refresh();
    }
}
