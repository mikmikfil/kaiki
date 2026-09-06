<?php

declare(strict_types=1);

namespace App\Domain\Availability\Contracts;

use App\Models\Departure;

/**
 * Seats a departure's stored counter still claims are held, but which are not
 * (spec AVL-38, ADR-0005).
 *
 * ## Why the counter alone cannot answer this
 *
 * `departures.seats_held` is a number. A hold is a number *with an expiry*, and
 * the expiry lives on the booking. So the counter is always a snapshot of what
 * was true when the sweeper last ran, and AVL-38 requires every availability
 * read to reach the right answer **without** the sweeper having run:
 *
 * > a backlogged queue can never cause an oversell and a fast sweeper can never
 * > release a hold a read still counts.
 *
 * This contract supplies the correction. `seats_held − expired` is what a read
 * should treat as held, and {@see Departure::seatsAvailable()} applies it.
 *
 * ## Batched, unlike its two neighbours
 *
 * {@see DeparturePersonsAboard} asks one departure at a time, which is fine for
 * the legal check because it runs once per departure a party is actually
 * booking. This runs on the **hottest read in the product** — a calendar month
 * is thirty-odd departures — and a per-departure query would be thirty, against
 * NFR-7's five-query budget and NFR-1's 150 ms p95. So it takes the whole set
 * and returns a map.
 *
 * ## Unregistered means zero, and zero is the safe direction
 *
 * With no implementation the correction is nothing, `seats_held` is taken at
 * face value, and the engine is *conservative* — it may refuse a seat that is
 * actually free, which costs a booking. The opposite default would sell a seat
 * that is not, which costs a guest their holiday.
 */
interface DepartureExpiredHolds
{
    /**
     * @param  iterable<Departure>  $departures
     * @return array<int, int> departure id => expired held seats
     */
    public function expiredHeldSeats(iterable $departures): array;
}
