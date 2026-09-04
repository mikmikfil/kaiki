<?php

declare(strict_types=1);

namespace App\Data\Availability;

use Spatie\LaravelData\Data;

/**
 * One local date's departures (spec AVL-29).
 *
 * Every requested date appears, including the empty ones. A calendar widget
 * paints a month and needs to know the difference between "no sailing" and "not
 * asked about" — dropping empty days would make the two indistinguishable, and
 * the widget would render a gap where it should render a closed day.
 */
final class AvailabilityDayData extends Data
{
    /** @param list<DepartureAvailabilityData> $departures */
    public function __construct(
        public readonly string $localDate,
        public readonly array $departures = [],
    ) {}

    public function hasAvailability(): bool
    {
        foreach ($this->departures as $departure) {
            if ($departure->available) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date' => $this->localDate,
            'available' => $this->hasAvailability(),
            'departures' => array_map(
                static fn (DepartureAvailabilityData $departure): array => $departure->toArray(),
                $this->departures,
            ),
        ];
    }
}
