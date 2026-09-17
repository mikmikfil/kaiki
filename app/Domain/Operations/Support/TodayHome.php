<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Availability\Support\LocalDay;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Departure;
use Illuminate\Support\Carbon;

/**
 * What the operator's home page reads (product owner, 2026-09-17, «version 3»).
 *
 * The home page is the day, by boat: the next departure first, with how many
 * are aboard, then four boxes that each answer one question, then the boats.
 * Everything it counts is here, so the page itself only lays numbers out.
 *
 * ## Few numbers, on purpose
 *
 * The six-figure overview that stood here before is still a widget and still
 * tested, but it is not on the home page: round 2 of the dashboard mockups was
 * "all too much", and the answer was one main panel with a few numbers. The
 * figures that survived are the ones a person on the quay acts on.
 *
 * ## Aboard means a guest ticked off, not a booking
 *
 * A family of four is one booking and four people walking up the gangway, and
 * the crew count people. So the progress bar counts `booking_guests` with a
 * `checked_in_at`, over the guests on committed bookings for that sailing.
 */
final class TodayHome
{
    /** Bookings in these states have people coming. */
    private const COMMITTED = [
        BookingStatus::Confirmed,
        BookingStatus::CheckedIn,
        BookingStatus::Completed,
    ];

    public function __construct(private readonly string $timezone) {}

    /**
     * The sailing still ahead of us, or under way now, soonest first, within a week.
     *
     * @return array{departure: Departure, starts: Carbon, underway: bool, today: bool, aboard: int, expected: int}|null
     */
    public function nextDeparture(?Carbon $now = null): ?array
    {
        $now ??= Carbon::now('UTC');

        $departure = Departure::query()
            ->with(['product.meetingPoint', 'vessel'])
            ->where('status', '!=', DepartureStatus::Cancelled->value)
            ->where('ends_at_utc', '>', $now)
            ->where('starts_at_utc', '<', $now->copy()->addDays(7))
            ->orderBy('starts_at_utc')
            ->first();

        if (! $departure instanceof Departure) {
            return null;
        }

        [$aboard, $expected] = $this->boarding($departure);
        $starts = $departure->starts_at_utc->copy()->setTimezone($this->timezone);

        return [
            'departure' => $departure,
            'starts' => $starts,
            'underway' => $departure->starts_at_utc->lessThanOrEqualTo($now),
            'today' => $starts->toDateString() === Carbon::now($this->timezone)->toDateString(),
            'aboard' => $aboard,
            'expected' => $expected,
        ];
    }

    /** Departures that sail today on the operator's clock, cancelled ones aside. */
    public function departuresToday(): int
    {
        $day = LocalDay::today($this->timezone);

        return Departure::query()
            ->where('status', '!=', DepartureStatus::Cancelled->value)
            ->where('starts_at_utc', '>=', $day->startUtc)
            ->where('starts_at_utc', '<', $day->endUtcExclusive)
            ->count();
    }

    /** Real bookings made in the last day: what «νέες» means on the box. */
    public function newBookings(?Carbon $now = null): int
    {
        $now ??= Carbon::now('UTC');

        return Booking::query()
            ->where('is_test', false)
            ->whereIn('status', array_map(static fn (BookingStatus $status): string => $status->value, [
                BookingStatus::PendingPayment,
                ...self::COMMITTED,
            ]))
            ->where('created_at', '>=', $now->copy()->subDay())
            ->count();
    }

    /**
     * People aboard, and people expected, for one sailing.
     *
     * @return array{0: int, 1: int}
     */
    public function boarding(Departure $departure): array
    {
        $guests = BookingGuest::query()
            ->whereHas('booking', static fn ($query) => $query
                ->where('departure_id', $departure->getKey())
                ->where('is_test', false)
                ->whereIn('status', array_map(static fn (BookingStatus $status): string => $status->value, self::COMMITTED)));

        $expected = (clone $guests)->count();
        $aboard = (clone $guests)->whereNotNull('checked_in_at')->count();

        return [$aboard, $expected];
    }
}
