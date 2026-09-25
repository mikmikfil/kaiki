<?php

declare(strict_types=1);

namespace App\Data\Availability;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * The departures calendar's answer for one page of days.
 */
final class DepartureCalendarResult
{
    /**
     * @param  list<DepartureCalendarDay>  $days  one per requested day, always
     * @param  Collection<int, Product>  $products  every trip on sale the calendar looked at
     * @param  string|null  $nextDate  the next day with a sailing after the range, asked only when the range is empty
     * @param  Collection<int, Product>|null  $listed  the trips with something in the range, for the «Εκδρομή» filter
     */
    public function __construct(
        public readonly array $days,
        public readonly Collection $products,
        public readonly ?string $nextDate = null,
        ?Collection $listed = null,
    ) {
        $this->listed = $listed ?? $products;
    }

    /** @var Collection<int, Product> */
    public readonly Collection $listed;

    /** Rows the filters kept, across every day. */
    public function count(): int
    {
        return array_sum(array_map(static fn (DepartureCalendarDay $day): int => count($day->rows), $this->days));
    }

    /** Rows before the filters: zero means nothing sails in the range at all. */
    public function unfilteredCount(): int
    {
        return array_sum(array_map(static fn (DepartureCalendarDay $day): int => $day->unfilteredCount, $this->days));
    }
}
