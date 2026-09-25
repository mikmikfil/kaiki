<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\Contracts\BulkVesselHoldSource;
use App\Domain\Availability\Contracts\ExcludingVesselHoldSource;
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

    /**
     * Load everything touching `$range`, in two queries.
     *
     * `$excludingBookingId` leaves one booking's own occupation out
     * (2026-09-25): a charter confirming asks whether anybody **else** has the
     * boat, and its own draft or `pending_payment` row is in exactly that window.
     */
    public static function forRange(Vessel $vessel, Window $range, ?int $excludingBookingId = null): self
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
            self::holdWindows($vessel, $padded, $excludingBookingId),
        );
    }

    /**
     * {@see self::forRange()} for a whole fleet at once (the departures
     * calendar, 2026-09-25).
     *
     * Three queries whatever the size of the fleet — departures, blocks, and
     * the live private holds — where calling `forRange()` per boat would be
     * three per boat. Each collector it returns is the same object `forRange()`
     * builds, holding only its own boat's rows, so every question asked of it
     * afterwards is answered by exactly the code the availability endpoint
     * runs.
     *
     * @param  Collection<int, Vessel>  $vessels
     * @return array<int, self> keyed by vessel id
     */
    public static function forVessels(Collection $vessels, Window $range): array
    {
        if ($vessels->isEmpty()) {
            return [];
        }

        $widest = (int) $vessels->max(static fn (Vessel $vessel): int => $vessel->effectiveTurnaroundBufferMinutes());
        $padded = $range->paddedBy($widest);
        $ids = $vessels->map(static fn (Vessel $vessel): int => (int) $vessel->getKey())->values()->all();

        /** @var Collection<int, Departure> $departures */
        $departures = Departure::query()
            ->whereIn('vessel_id', $ids)
            // AVL-28, as in `forRange()`: a cancelled departure occupies nothing.
            ->whereNot('status', DepartureStatus::Cancelled)
            ->where('starts_at_utc', '<', $padded->endUtc)
            ->where('ends_at_utc', '>', $padded->startUtc)
            ->orderBy('starts_at_utc')
            ->get();

        $blocks = VesselCalendar::blocksForVessels($vessels, $padded);
        $holds = self::holdWindowsByVessel($vessels, $ids, $padded);

        $collectors = [];

        foreach ($vessels as $vessel) {
            $key = (int) $vessel->getKey();

            $collectors[$key] = new self(
                $vessel->effectiveTurnaroundBufferMinutes(),
                $departures->where('vessel_id', $key)->values(),
                $blocks->where('vessel_id', $key)->values(),
                $holds[$key] ?? [],
            );
        }

        return $collectors;
    }

    /**
     * The fleet's live private holds: one query for a source that can answer
     * in bulk, one per boat for a source that cannot.
     *
     * @param  Collection<int, Vessel>  $vessels
     * @param  list<int>  $ids
     * @return array<int, list<Window>>
     */
    private static function holdWindowsByVessel(Collection $vessels, array $ids, Window $range): array
    {
        $now = Carbon::now();
        $windows = [];

        /** @var iterable<VesselHoldSource> $sources */
        $sources = app()->tagged(self::HOLD_SOURCE_TAG);

        foreach ($sources as $source) {
            if ($source instanceof BulkVesselHoldSource) {
                foreach ($source->holdWindowsByVessel($ids, $range, $now) as $vesselId => $held) {
                    foreach ($held as $window) {
                        $windows[$vesselId][] = $window;
                    }
                }

                continue;
            }

            foreach ($vessels as $vessel) {
                foreach ($source->holdWindows($vessel, $range, $now) as $window) {
                    $windows[(int) $vessel->getKey()][] = $window;
                }
            }
        }

        return $windows;
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
    private static function holdWindows(Vessel $vessel, Window $range, ?int $excludingBookingId = null): array
    {
        $now = Carbon::now();
        $windows = [];

        /** @var iterable<VesselHoldSource> $sources */
        $sources = app()->tagged(self::HOLD_SOURCE_TAG);

        foreach ($sources as $source) {
            $found = $excludingBookingId !== null && $source instanceof ExcludingVesselHoldSource
                ? $source->holdWindowsExcluding($vessel, $range, $now, $excludingBookingId)
                : $source->holdWindows($vessel, $range, $now);

            foreach ($found as $window) {
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
