<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Data\CheckInOverride;
use App\Domain\Booking\Support\CheckInWindow;
use App\Enums\BookingStatus;
use App\Events\BookingCheckedIn;
use App\Events\CheckInOverridden;
use App\Exceptions\CheckInRefused;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\User;
use App\Support\Format\DateTimeFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ticking one person aboard (spec BKG-20, BKG-21, BKG-22).
 *
 * ## Idempotent, because the thing scanning it is a phone camera
 *
 * A QR scanner fires as fast as it can focus, and a crew member holding a
 * phone in one hand on a moving pier will scan the same ticket three times
 * without noticing. So a guest who is already checked in is a **no-op that
 * returns false**, not an error and not a second timestamp — the first scan is
 * the one that happened, and overwriting `checked_in_at` would quietly move
 * the only record of when somebody actually boarded.
 *
 * The booking transition is a conditional update rather than a read-then-write
 * for the same reason: two crew members scanning two guests on the same
 * booking at the same moment must produce one transition, not a race.
 *
 * ## BKG-22's two edges, and only one of them has a way through
 *
 * Early is an operator decision: {@see CheckInOverride} carries the reason,
 * refuses to exist without one, and {@see CheckInOverridden} writes it to the
 * trail. Late is not — a check-in recorded after `ends_at_utc` is a false
 * manifest rather than an early judgement call, and there is no parameter here
 * that permits it.
 *
 * ## No locks, deliberately
 *
 * AVL-45 orders the locks that guard `seats_sold` and `seats_held`. Check-in
 * touches neither: the seat was sold at checkout and nothing here changes a
 * counter, releases capacity or moves money. Taking a vessel lock to write a
 * timestamp would put a scan on a pier into contention with every confirmation
 * on that boat, for no invariant.
 *
 * ## The audit row goes out after the commit
 *
 * AVL-46 in its smaller form. `RecordAuditLog` is queued; dispatching inside
 * the transaction would mean a rolled-back check-in that left a permanent row
 * saying it happened.
 */
final class CheckInGuest
{
    /**
     * @param  User  $by  who scanned it — `booking_guests.checked_in_by_user_id`
     * @param  CheckInOverride|null  $override  required only when the window has not opened
     * @return bool false when this guest was already aboard
     *
     * @throws CheckInRefused when the booking cannot be checked in, or the
     *                        window is shut and no override applies
     */
    public function __invoke(BookingGuest $guest, User $by, ?CheckInOverride $override = null): bool
    {
        $booking = $guest->booking;

        if (! $booking instanceof Booking) {
            throw CheckInRefused::unknownTicket();
        }

        if ($guest->checked_in_at !== null) {
            return false;
        }

        $this->assertBookingIsCheckable($booking);

        $now = Carbon::now();
        $window = CheckInWindow::forBooking($booking, $booking->product);

        // The hard edge first. There is no override past it, so nothing below
        // this line needs to consider the case.
        if ($window->hasClosed($now)) {
            throw CheckInRefused::tripHasEnded();
        }

        $minutesEarly = 0;

        if ($window->isEarly($now)) {
            if (! $override instanceof CheckInOverride) {
                throw CheckInRefused::windowNotOpen(
                    DateTimeFormatter::time($window->opensAt),
                );
            }

            $minutesEarly = (int) $now->diffInMinutes($window->opensAt, absolute: true);
        }

        $transitioned = DB::transaction(function () use ($guest, $booking, $by, $now): bool {
            $claimed = DB::table('booking_guests')
                ->where('id', $guest->getKey())
                ->whereNull('checked_in_at')
                ->update([
                    'checked_in_at' => $now,
                    'checked_in_by_user_id' => $by->getKey(),
                    // A guest who turns up is not a no-show, whatever anybody
                    // marked earlier. BKG-23 makes the mark reversible and this
                    // is the reversal that needs no operator.
                    'no_show' => false,
                    'updated_at' => $now,
                ]);

            if ($claimed < 1) {
                // Another scanner won. Not an error — see the class docblock.
                return false;
            }

            // BKG-21: *at least one* guest. Conditional on `confirmed`, so the
            // second guest on the same booking changes nothing and the second
            // scanner does not get a second event.
            return DB::table('bookings')
                ->where('id', $booking->getKey())
                ->where('status', BookingStatus::Confirmed->value)
                ->update([
                    'status' => BookingStatus::CheckedIn->value,
                    'checked_in_at' => $now,
                    'updated_at' => $now,
                ]) > 0;
        });

        $guest->refresh();

        if ($guest->checked_in_by_user_id !== $by->getKey()) {
            // Somebody else's scan landed first. Nothing to announce.
            return false;
        }

        if ($override instanceof CheckInOverride) {
            CheckInOverridden::dispatch($booking, $override, $minutesEarly);
        }

        if ($transitioned) {
            BookingCheckedIn::dispatch($booking->getKey(), $booking->tenant_id);
        }

        return true;
    }

    /**
     * Can this booking be checked in at all?
     *
     * `confirmed` and `checked_in` both pass — the second is how the third
     * guest on a family booking gets aboard. Everything else is refused with a
     * sentence naming the status, because the crew member reading it is
     * standing in front of somebody holding a phone and needs to know whether
     * to send them to the office.
     */
    private function assertBookingIsCheckable(Booking $booking): void
    {
        if ($booking->status === BookingStatus::Confirmed || $booking->status === BookingStatus::CheckedIn) {
            return;
        }

        throw CheckInRefused::bookingNotCheckable($booking->status);
    }
}
