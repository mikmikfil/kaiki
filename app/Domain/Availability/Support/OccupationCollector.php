<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\Contracts\VesselHoldSource;
use App\Domain\Availability\VesselCalendar;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Illuminate\Support\Carbon;
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
 *
 * ## The fourth source expires, which is why it is asked for every time
 *
 * AVL-3.4 counts a `per_vessel` booking in `draft` with an unexpired hold.
 * Unlike the other three it is not a row that sits there — it occupies the boat
 * for twenty minutes and then stops, with nothing written and nothing deleted.
 * AVL-33 depends on exactly that: a hold hides conflicting zero-sold departures
 * *"but not cancelled"*, and *"if the hold expires they become available
 * again."*
 *
 * The windows come from {@see VesselHoldSource}, which has no implementation
 * until M2 because `bookings` does not exist — so today it reports none, and
 * the behaviour is already built and tested.
 */
final class OccupationCollector
{
    /** What M2 tags its hold reader with. Nothing is tagged today. */
    public const HOLD_SOURCE_TAG = 'availability.vessel-holds';

    /**
     * @param  Collection<int, Departure>  $departures  every non-cancelled one in range
     * @param  Collection<int, VesselBlock>  $blocks
     * @param  list<Window>  $holdWindows  AVL-3.4, empty until M2
     */
    private function __construct(
        private readonly int $bufferMinutes,
        private readonly Collection $departures,
        private readonly Collection $blocks,
        private readonly array $holdWindows = [],
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

        return new self(
            $buffer,
            $departures,
            VesselCalendar::blocksFor($vessel, $padded),
            self::holdWindows($vessel, $padded),
        );
    }

    /**
     * Private holds currently occupying the boat (AVL-3.4).
     *
     * Resolved from the container rather than injected, because this class is
     * constructed statically from three call sites and threading an optional
     * dependency through all of them would be worse than one `app()` in a
     * factory method. There is nothing tagged until M2, so the list is empty.
     *
     * @return list<Window>
     */
    private static function holdWindows(Vessel $vessel, Window $range): array
    {
        $now = Carbon::now();
        $windows = [];

        /** @var iterable<VesselHoldSource> $sources */
        $sources = app()->tagged(self::HOLD_SOURCE_TAG);

        foreach ($sources as $source) {
            foreach ($source->holdWindows($vessel, $range, $now) as $window) {
                $windows[] = $window;
            }
        }

        return $windows;
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

        $blocked = $this->blocks->contains(
            fn (VesselBlock $block): bool => $window->conflictsWith($block->window(), $this->bufferMinutes),
        );

        if ($blocked) {
            return false;
        }

        foreach ($this->holdWindows as $hold) {
            if ($window->conflictsWith($hold, $this->bufferMinutes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Does a private hold cover this window (AVL-33)?
     *
     * Asked separately from {@see self::isFree()} because the *reason* differs:
     * a departure hidden by somebody else's hold is not "the boat is busy
     * forever", it is "someone is at the checkout". The distinction matters to
     * an operator reading the panel and to a guest deciding whether to wait.
     */
    public function isHeldPrivately(Window $window): bool
    {
        foreach ($this->holdWindows as $hold) {
            if ($window->conflictsWith($hold, $this->bufferMinutes)) {
                return true;
            }
        }

        return false;
    }
}
