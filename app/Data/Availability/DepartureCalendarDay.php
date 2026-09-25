<?php

declare(strict_types=1);

namespace App\Data\Availability;

/**
 * One local day of the departures calendar, with the rows the filters kept.
 */
final class DepartureCalendarDay
{
    /**
     * @param  list<DepartureCalendarRow>  $rows  what the filters kept, in time order
     * @param  int  $unfilteredCount  rows before the filters, so an empty day can say why
     */
    public function __construct(
        public readonly string $localDate,
        public readonly array $rows,
        public readonly int $unfilteredCount,
    ) {}

    /** The timed sailings, cancelled ones included — the «N αναχωρήσεις». */
    public function departureCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (DepartureCalendarRow $row): bool => $row->kind === DepartureCalendarRow::KIND_DEPARTURE,
        ));
    }

    /**
     * `all` when every sailing of the day was cancelled and the weather is why,
     * `some` when the weather took only part of it, null otherwise.
     */
    public function weather(): ?string
    {
        $departures = array_values(array_filter(
            $this->rows,
            static fn (DepartureCalendarRow $row): bool => $row->kind === DepartureCalendarRow::KIND_DEPARTURE,
        ));

        $weather = array_filter($departures, static fn (DepartureCalendarRow $row): bool => $row->isWeather());

        if ($weather === []) {
            return null;
        }

        $allCancelled = array_filter($departures, static fn (DepartureCalendarRow $row): bool => ! $row->isCancelled()) === [];

        return $allCancelled ? 'all' : 'some';
    }

    /**
     * The dot on the day's pill: the best thing a guest could still do that day.
     *
     * `available` when anything has room, `few` when only a little, `cancelled`
     * for a day the weather took, `full` otherwise, and null for a day with
     * nothing on it.
     */
    public function dot(): ?string
    {
        if ($this->rows === []) {
            return null;
        }

        if ($this->weather() === 'all') {
            return 'cancelled';
        }

        $statuses = array_map(static fn (DepartureCalendarRow $row): string => $row->status, $this->rows);

        if (in_array(DepartureCalendarRow::AVAILABLE, $statuses, true)) {
            return 'available';
        }

        return in_array(DepartureCalendarRow::FEW, $statuses, true) ? 'few' : 'full';
    }
}
