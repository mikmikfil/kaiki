<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Availability\Contracts\BulkVesselHoldSource;
use App\Domain\Availability\Contracts\DepartureExpiredHolds;
use App\Domain\Availability\Contracts\DeparturePersonsAboard;
use App\Domain\Availability\Contracts\ExcludingVesselHoldSource;
use App\Domain\Availability\Contracts\VesselHoldSource;
use App\Domain\Availability\Support\Window;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What `bookings` tells the availability engine (spec AVL-25, AVL-33, AVL-38).
 *
 * ## Three seams, filled together, on purpose
 *
 * M1 shipped three interfaces with no implementations, each with a docblock
 * saying *"M2 adds one class and one `tag()` line"*. This is that class. They
 * are one class rather than three because they are one question asked three
 * ways — *what do the bookings on this boat currently mean?* — and because
 * three files that must agree about what "an unexpired hold" is are three
 * chances to disagree. {@see self::scopeLiveHold()} is that definition, once.
 *
 * ## Each answer, and what breaks without it
 *
 * - **{@see DeparturePersonsAboard}** — AVL-25's legal check counts *people*,
 *   infants included, against the boat's certificate. `seats_sold` counts
 *   *seats*. Two adults and two infants is two seats and four people, and a
 *   boat certified for ten can be legally full and commercially empty. Without
 *   this the check reports zero and a boat sails illegally full of infants,
 *   which is the sentence M1 wrote into the contract's own docblock.
 *
 * - **{@see VesselHoldSource}** — AVL-3.4's fourth occupation source, and the
 *   only one that expires by itself. A guest holding a private charter blocks
 *   the boat for fifteen minutes; when the hold lapses, conflicting departures
 *   become available again with nothing written and nothing deleted (AVL-33).
 *   Without this two guests can be at the checkout for the same boat on the
 *   same afternoon.
 *
 * - **{@see DepartureExpiredHolds}** — the read-side half of AVL-38. Without
 *   it a lapsed hold keeps its seats off sale until the sweeper catches up, so
 *   a queue backlog quietly costs bookings.
 *
 * ## Committed seats are not counted here
 *
 * `personsAboard` deliberately includes committed *and* held bookings, because
 * AVL-25 asks who is aboard and a guest at the gateway will be. But the
 * *commercial* seat count still comes from `departures.seats_sold`, and adding
 * these numbers together anywhere would double-count. The two questions have
 * different answers and different owners, which is why they are different
 * methods on different contracts.
 */
final class BookingHoldSource implements BulkVesselHoldSource, DepartureExpiredHolds, DeparturePersonsAboard, ExcludingVesselHoldSource
{
    public function personsAboard(Departure $departure): int
    {
        // Every person, counted and non-counted alike — `pax_total`, not
        // `pax_capacity_total`. That difference is the whole requirement.
        return (int) Booking::query()
            ->where('departure_id', $departure->getKey())
            ->where(function ($query): void {
                $query
                    ->whereIn('status', self::committingStatuses())
                    ->orWhere(fn ($held) => $this->scopeLiveHold($held));
            })
            ->sum('pax_total');
    }

    /** @return array<int, int> */
    public function expiredHeldSeats(iterable $departures): array
    {
        $ids = [];

        foreach ($departures as $departure) {
            $ids[] = $departure->getKey();
        }

        if ($ids === []) {
            return [];
        }

        // One grouped query for the whole set — the read this serves is the
        // hottest in the product (NFR-7's five-query budget, NFR-1's 150 ms).
        $rows = Booking::query()
            ->selectRaw('departure_id, SUM(pax_capacity_total) as seats')
            ->whereIn('departure_id', $ids)
            ->where('status', BookingStatus::Draft->value)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->groupBy('departure_id')
            ->get();

        $expired = [];

        foreach ($rows as $row) {
            $expired[(int) $row->getAttribute('departure_id')] = (int) $row->getAttribute('seats');
        }

        return $expired;
    }

    /** @return list<Window> */
    public function holdWindows(Vessel $vessel, Window $range, Carbon $now): array
    {
        return $this->holdWindowsByVessel([(int) $vessel->getKey()], $range, $now)[(int) $vessel->getKey()] ?? [];
    }

    /**
     * The same read for a whole fleet, in one query (the departures calendar,
     * 2026-09-25). {@see self::holdWindows()} is this with one boat, so the two
     * cannot disagree about what a live private hold is.
     *
     * @param  list<int>  $vesselIds
     * @return array<int, list<Window>>
     */
    public function holdWindowsByVessel(array $vesselIds, Window $range, Carbon $now): array
    {
        return $this->occupyingWindows($vesselIds, $range, $now, null);
    }

    /**
     * {@see self::holdWindows()} without one booking — a charter asking, at its
     * own confirmation, whether anybody **else** has the boat (2026-09-25).
     *
     * @return list<Window>
     */
    public function holdWindowsExcluding(Vessel $vessel, Window $range, Carbon $now, int $bookingId): array
    {
        return $this->occupyingWindows([(int) $vessel->getKey()], $range, $now, $bookingId)[(int) $vessel->getKey()] ?? [];
    }

    /**
     * Every private-charter booking occupying these boats in `$range`.
     *
     * {@see Booking::occupiesVesselWindow()}, as a query (2026-09-25): a
     * charter that is not per-seat occupies its window while its draft hold is
     * live **and** from the redirect to the gateway onwards — `pending_payment`,
     * `confirmed`, `checked_in`, `completed`. Until then only the draft counted,
     * and no charter draft was ever given a hold, so a charter booked online
     * occupied its boat in no state at all: the calendar went on offering the
     * day and a second guest could pay for it.
     *
     * Quote-mode bookings are included on purpose: `SendQuote`'s block is
     * deleted when the quote is accepted, and from then on the booking itself
     * is what holds the boat.
     *
     * @param  list<int>  $vesselIds
     * @return array<int, list<Window>>
     */
    private function occupyingWindows(array $vesselIds, Window $range, Carbon $now, ?int $excludingBookingId): array
    {
        if ($vesselIds === []) {
            return [];
        }

        $bookings = Booking::query()
            ->whereIn('vessel_id', $vesselIds)
            ->where('mode', '!=', BookingMode::PerSeat->value)
            ->where(static function (Builder $query) use ($now): void {
                $query
                    ->where(static fn (Builder $held): Builder => $held
                        ->where('status', BookingStatus::Draft->value)
                        ->whereNotNull('hold_expires_at')
                        // `$now` rather than `now()`: the caller decides what
                        // "currently" means, and a test freezing time must be
                        // able to move the boundary without the answer being
                        // read off the wall clock instead.
                        ->where('hold_expires_at', '>', $now))
                    ->orWhereIn('status', self::committingStatuses());
            })
            ->when($excludingBookingId !== null, static fn (Builder $query): Builder => $query->whereKeyNot($excludingBookingId))
            // Overlap, not containment: a hold starting before the range and
            // ending inside it occupies the boat just as much.
            ->where('starts_at_utc', '<', $range->endUtc)
            ->where('ends_at_utc', '>', $range->startUtc)
            ->get();

        $windows = [];

        foreach ($bookings as $booking) {
            $windows[(int) $booking->vessel_id][] = Window::of($booking->starts_at_utc, $booking->ends_at_utc);
        }

        return $windows;
    }

    /**
     * Statuses whose pax are committed rather than held.
     *
     * Derived from the enum rather than listed, so a new status is sorted by
     * {@see BookingStatus::committingSeats()} and not by somebody remembering
     * this array exists.
     *
     * @return list<string>
     */
    private static function committingStatuses(): array
    {
        return array_values(array_map(
            static fn (BookingStatus $status): string => $status->value,
            array_filter(
                BookingStatus::cases(),
                static fn (BookingStatus $status): bool => $status->committingSeats(),
            ),
        ));
    }

    /**
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    private function scopeLiveHold(Builder $query): Builder
    {
        return $query
            ->where('status', BookingStatus::Draft->value)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '>', now());
    }
}
