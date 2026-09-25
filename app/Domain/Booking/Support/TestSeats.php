<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Departure;

/**
 * Seats taken by test bookings, kept out of every real figure (2026-09-25).
 *
 * ## The rule
 *
 * A test booking (`is_test`, from a sandbox key or checkout) **holds its seats
 * while it is live**, like any other: it is in `departures.seats_sold`, so the
 * widget cannot sell a seat a test is sitting on, and cancelling the test
 * gives the seat back through the same path as a real booking. One counter,
 * moved one way, is what keeps `seats_sold` true under concurrency; a second
 * counter for tests would be a second thing to keep in step.
 *
 * What a test seat never does is **count as a guest**: it does not make a
 * sailing guaranteed (AVL-48, irreversible), it is not on the port-authority
 * manifest, and it is not in the pax and occupancy figures. Those read
 * `seats_sold` minus the test seats, through here.
 */
final class TestSeats
{
    /** Test seats committed on one departure, excluding one booking if given. */
    public static function on(Departure $departure, ?Booking $except = null): int
    {
        return (int) Booking::query()
            ->where('departure_id', $departure->getKey())
            ->where('is_test', true)
            ->whereIn('status', self::committingStatuses())
            ->when($except instanceof Booking, static fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->sum('pax_capacity_total');
    }

    /**
     * `seats_sold` less the test seats, as SQL on a `departures` row.
     *
     * For aggregates (`SUM(...)`) and comparisons (`... < min_pax`). The
     * statuses are enum values, never input, so they are inlined.
     */
    public static function realSoldSql(string $departures = 'departures'): string
    {
        $statuses = implode(', ', array_map(
            static fn (string $status): string => "'{$status}'",
            self::committingStatuses(),
        ));

        return "({$departures}.seats_sold - COALESCE((SELECT SUM(test_bookings.pax_capacity_total)"
            . ' FROM bookings AS test_bookings'
            . " WHERE test_bookings.departure_id = {$departures}.id"
            . ' AND test_bookings.is_test = 1'
            . ' AND test_bookings.deleted_at IS NULL'
            . " AND test_bookings.status IN ({$statuses})), 0))";
    }

    /** @return list<string> */
    private static function committingStatuses(): array
    {
        return array_values(array_map(
            static fn (BookingStatus $status): string => $status->value,
            array_filter(BookingStatus::cases(), static fn (BookingStatus $status): bool => $status->committingSeats()),
        ));
    }
}
