<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\VesselCalendar;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Illuminate\Support\Collection;

/**
 * Every AVL-3 occupation of one vessel across a whole range, loaded once.
 *
 * ## Why this exists rather than calling `VesselCalendar` per departure
 *
 * {@see VesselCalendar} answers one window at a time, which is right for a
 * single check and ruinous for a 62-day availability response: sixty departures
 * would be sixty block queries. NFR-7 caps a 14-day response at **five queries
 * in total**, so the occupations are fetched once for the outer range and every
 * per-departure question is then answered in PHP.
 *
 * It is not a second copy of the query — the blocks come from
 * `VesselCalendar::blocksFor()`, so ADR-0023's rule that one class owns vessel
 * occupancy still holds. This is a caching reader, not a competing one.
 *
 * ## One departures query serves two purposes
 *
 * The vessel's departures across the range are loaded **whole**, and the
 * product's own are filtered out of that set in PHP by
 * {@see self::departuresFor()}. Querying them separately would be the obvious
 * shape and would cost a second query out of a budget of five — the product's
 * departures are a subset of its vessel's, so one round trip answers both.
 *
 * ## What counts as an occupation, and the rule that surprises people
 *
 * AVL-10: a departure with no sold and no held seats is **not** an occupation.
 * Two empty departures may share a boat and an hour. The moment one takes a
 * seat it becomes an occupation and the others stop being sellable (AVL-11) —
 * without being cancelled, which is why this is a read-time question rather
 * than a stored flag.
 *
 * AVL-9: nothing conflicts with itself.
 */
final class OccupationCollector
{
    /**
     * @param  Collection<int, Departure>  $departures  every non-cancelled one in range
     * @param  Collection<int, VesselBlock>  $blocks
     */
    private function __construct(
        private readonly int $bufferMinutes,
        private readonly Collection $departures,
        private readonly Collection $blocks,
    ) {}

    /** Load everything touching `$range`, in two queries. */
    public static function forRange(Vessel $vessel, Window $range): self
    {
        $buffer = $vessel->effectiveTurnaroundBufferMinutes();
        $padded = $range->paddedBy($buffer);

        /** @var Collection<int, Departure> $departures */
        $departures = Departure::query()
            ->where('vessel_id', $vessel->getKey())
            // AVL-28: a cancelled departure occupies nothing and is never
            // offered, so it is excluded once here rather than twice later.
            ->whereNot('status', DepartureStatus::Cancelled)
            ->where('starts_at_utc', '<', $padded->endUtc)
            ->where('ends_at_utc', '>', $padded->startUtc)
            ->orderBy('starts_at_utc')
            ->get();

        return new self($buffer, $departures, VesselCalendar::blocksFor($vessel, $padded));
    }

    /**
     * This product's departures, from the set already loaded.
     *
     * Filtered to `$range` as well as to the product: the load used the
     * **padded** window, which reaches an hour either side and would otherwise
     * offer a guest a departure on a date they did not ask about.
     *
     * @return Collection<int, Departure>
     */
    public function departuresFor(Product $product, Window $range): Collection
    {
        return $this->departures
            ->filter(static fn (Departure $departure): bool => $departure->product_id === $product->getKey()
                && $departure->starts_at_utc->greaterThanOrEqualTo($range->startUtc)
                && $departure->starts_at_utc->lessThan($range->endUtc))
            ->values();
    }

    /**
     * Is the boat free for this window, ignoring `$excluding` (AVL-7, AVL-9)?
     *
     * Everything here is PHP over preloaded collections, which is the whole
     * point of the class.
     */
    public function isFree(Window $window, ?Departure $excluding = null): bool
    {
        $clashing = $this->departures->first(
            fn (Departure $departure): bool => $departure->getKey() !== $excluding?->getKey()
                // AVL-10: an empty departure is not an occupation.
                && ($departure->seats_sold > 0 || $departure->seats_held > 0)
                && $window->conflictsWith(Window::of($departure->starts_at_utc, $departure->ends_at_utc), $this->bufferMinutes),
        );

        if ($clashing !== null) {
            return false;
        }

        return ! $this->blocks->contains(
            fn (VesselBlock $block): bool => $window->conflictsWith($block->window(), $this->bufferMinutes),
        );
    }
}
