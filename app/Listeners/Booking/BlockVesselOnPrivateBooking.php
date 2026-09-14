<?php

declare(strict_types=1);

namespace App\Listeners\Booking;

use App\Domain\Availability\Actions\RecomputeDepartureBlockedFlags;
use App\Domain\Availability\VesselCalendar;
use App\Domain\Booking\Actions\SendQuote;
use App\Enums\BlockReason;
use App\Enums\BookingMode;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Str;

/**
 * A whole-boat booking takes the boat off every other product (AVL-35).
 *
 * ## The gap this closes
 *
 * AVL-35 has been in §5.4 since M0: *"A `per_vessel` booking creates a
 * `VesselBlock` with reason `private_booking` linked to the booking. Cancelling
 * the booking removes or voids the block in the same transaction."*
 * {@see BlockReason::PrivateBooking} was written for it, the calendar offers it
 * in its dropdown and the factory builds it — and **nothing ever created one**.
 * The requirement was specified, its vocabulary was built, and the line that
 * writes the row was never added.
 *
 * It stayed invisible because the demo data gives every product its own boat.
 * The moment one boat sells more than one product — which is the normal shape
 * of a small operator, and the shape of the first real catalogue this was
 * tested against — it is a double sale: a private charter is confirmed for the
 * 20th and the shared trip on the same boat that morning keeps selling seats.
 *
 * ## Why a block and not a check at booking time
 *
 * AVL-11 is deliberate that two *departures* of different products may overlap
 * on one boat — operators schedule both and let the bookings decide. So the
 * conflict cannot be refused when the schedule is built; it only becomes real
 * when somebody buys the boat. A `VesselBlock` is exactly the object that says
 * "this hull is spoken for between these two instants", every availability path
 * already consults it through {@see VesselCalendar},
 * and {@see RecomputeDepartureBlockedFlags}
 * already turns it into the flag the panel reads. Nothing new has to learn
 * about private bookings.
 *
 * ## Why `updateOrCreate` on `booking_id`
 *
 * The same reason {@see SendQuote} does it: one
 * block per booking, refreshed rather than duplicated. A booking that is
 * confirmed, cancelled and confirmed again — or a quote that became a booking,
 * which already has a block of its own keyed the same way — must not leave a
 * second hold on a boat nobody can then sell. The quote's block is *replaced*
 * here rather than added to, which is also right: the window it held was the
 * offer's expiry, and the window it should hold now is the trip.
 */
final class BlockVesselOnPrivateBooking
{
    public function handleConfirmed(BookingConfirmed $event): void
    {
        $booking = $this->booking($event->bookingId, $event->tenantId);

        // Per-seat bookings share the boat by design; only a whole-boat sale
        // takes it out of circulation. A booking with no vessel — one whose
        // product has not been given one yet — has no hull to block.
        if (! $booking instanceof Booking
            || $booking->vessel_id === null
            || $booking->product?->mode !== BookingMode::PerVessel) {
            return;
        }

        Tenancy::forTenant($booking->tenant, static function () use ($booking): void {
            VesselBlock::query()->updateOrCreate(
                ['booking_id' => $booking->getKey()],
                [
                    'tenant_id' => $booking->tenant_id,
                    'vessel_id' => $booking->vessel_id,
                    'starts_at_utc' => $booking->starts_at_utc,
                    'ends_at_utc' => $booking->ends_at_utc,
                    'local_date' => $booking->local_date,
                    'local_end_date' => $booking->local_date,
                    // The trip's own window, not the whole day: the turnaround
                    // buffer is the vessel's and is applied by the availability
                    // check, so an all-day block here would refuse an evening
                    // sailing that a morning charter leaves plenty of room for.
                    'is_all_day' => false,
                    'reason' => BlockReason::PrivateBooking,
                    'title' => Str::limit((string) $booking->reference, 120),
                ],
            );
        });
    }

    /**
     * The boat goes back on sale.
     *
     * Deleted rather than voided. AVL-35 allows either, and a row with no
     * effect is a row every later query has to remember to exclude — the
     * booking keeps the history, which is where history belongs.
     */
    public function handleCancelled(BookingCancelled $event): void
    {
        $booking = $this->booking($event->bookingId, $event->tenantId);

        if (! $booking instanceof Booking) {
            return;
        }

        Tenancy::forTenant($booking->tenant, static function () use ($booking): void {
            // One at a time, through the model. A `->delete()` on the query
            // builder is a mass delete: it never loads the rows and so never
            // fires {@see \App\Observers\VesselBlockObserver}, which is what
            // recomputes `departures.is_blocked`. The block row would vanish
            // and every departure it had closed would stay closed — a boat
            // taken off sale by a booking that no longer exists.
            VesselBlock::query()
                ->where('booking_id', $booking->getKey())
                ->where('reason', BlockReason::PrivateBooking)
                ->get()
                ->each(static fn (VesselBlock $block) => $block->delete());
        });
    }

    /** The booking, read inside its own tenant. */
    private function booking(int $bookingId, int $tenantId): ?Booking
    {
        return Tenancy::withoutTenancy(
            static fn (): ?Booking => Booking::query()
                ->with(['product', 'tenant'])
                ->where('tenant_id', $tenantId)
                ->find($bookingId),
        );
    }
}
