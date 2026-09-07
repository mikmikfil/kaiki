<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Availability\Support\Window;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Illuminate\Support\Collection;

/**
 * The one port onto vessel occupancy (ADR-0023 Option C, spec AVL-3, AVL-12a).
 *
 * ## Why a port at all
 *
 * AVL-12a keeps the three occupation shapes — `departures`, `vessel_blocks`,
 * and per-vessel booking windows — as three separate tables, and buys back the
 * simplicity by hiding the union behind this class: *"the only class in the
 * codebase permitted to query vessel occupancy"*. Nothing outside
 * `app/Domain/Availability` may know there are three.
 *
 * That matters because "is this boat free" is asked from the panel, the
 * availability endpoint, the booking path and the iCal feed, and four callers
 * each remembering to union three tables is four chances to forget one. The
 * forgotten table is always the one that was empty in development.
 *
 * **Two of the three shapes are here now.** `departures` arrived with #28 and
 * `vessel_blocks` with #29; per-vessel booking windows follow in M2, landing
 * here rather than at a call site, so every caller written against this port
 * gains them for free.
 *
 * ## What counts as an occupation
 *
 * AVL-10 is the surprising rule: *"a Departure with `seats_sold = 0` and
 * `seats_held = 0` is **not** an occupation."* Empty departures may overlap each
 * other freely — an operator legitimately schedules two products on one boat at
 * the same hour and lets the bookings decide. The moment a seat is committed or
 * held on one, it becomes an occupation and the others stop being sellable
 * (AVL-11).
 *
 * So there are two different questions, and they have different answers:
 * {@see self::occupationsFor()} for "is this boat actually busy", and
 * {@see self::conflictingDepartures()} for "what should the operator be warned
 * about", which includes the empty ones precisely because they are the thing
 * that will silently stop being sellable.
 */
final class VesselCalendar
{
    /**
     * Everything that genuinely occupies `$vessel` during `$window` (AVL-3).
     *
     * Cancelled departures are excluded — they occupy nothing — and so are
     * empty ones, per AVL-10.
     *
     * @param  Departure|null  $excluding  AVL-9: an occupation never conflicts with itself
     * @return Collection<int, Departure>
     */
    public static function occupationsFor(Vessel $vessel, Window $window, ?Departure $excluding = null): Collection
    {
        return self::departuresNear($vessel, $window, $excluding)
            ->filter(static fn (Departure $departure): bool => $departure->seats_sold > 0 || $departure->seats_held > 0)
            ->values();
    }

    /**
     * Departures the operator should be warned about (AVL-11, AVL-12).
     *
     * **Includes empty departures**, which is the point: they are legal, and
     * they are exactly what will quietly stop being sellable the moment the
     * first seat is sold on any one of them. A warning that only fired on
     * genuine occupations would fire after it was useful.
     *
     * AVL-12: *"any Departure other than the one under evaluation"*, regardless
     * of product. Two departures of the same product on one boat at overlapping
     * times would double-book the vessel just as surely as two products would.
     *
     * @return Collection<int, Departure>
     */
    public static function conflictingDepartures(Vessel $vessel, Window $window, ?Departure $excluding = null): Collection
    {
        return self::departuresNear($vessel, $window, $excluding);
    }

    /**
     * Is the boat free for this window, allowing for its turnaround (AVL-7)?
     */
    public static function isFree(Vessel $vessel, Window $window, ?Departure $excluding = null): bool
    {
        return self::occupationsFor($vessel, $window, $excluding)->isEmpty()
            && self::blocksFor($vessel, $window)->isEmpty();
    }

    /**
     * Blocks occupying `$vessel` during `$window` (AVL-3).
     *
     * **A block is always an occupation**, unlike an empty departure (AVL-10):
     * a boat out of the water for maintenance is out of the water whether or
     * not anyone wanted it. That asymmetry is the reason the two shapes are
     * queried separately here rather than folded into one list of intervals.
     *
     * @return Collection<int, VesselBlock>
     */
    public static function blocksFor(Vessel $vessel, Window $window): Collection
    {
        $buffer = $vessel->effectiveTurnaroundBufferMinutes();

        return VesselBlock::query()
            ->forVessel($vessel->getKey())
            // Narrowed on `vblocks_vessel_window_idx` by the padded window;
            // the exact AVL-7 predicate then runs in PHP, because AVL-8 keeps
            // the buffer out of the query.
            ->overlapping($window->paddedBy($buffer))
            ->get()
            ->filter(static fn (VesselBlock $block): bool => $window->conflictsWith($block->window(), $buffer))
            ->values();
    }

    /**
     * Which of these boats are busy at all during `$window` (#105).
     *
     * The same question as {@see self::isFree()}, asked about a fleet in **two
     * queries instead of two per boat**. The catalogue search needs it for every
     * charter an operator sells, and the per-vessel form would make the search
     * N+1 across the fleet — which is the failure its own acceptance criterion
     * names.
     *
     * It lives here rather than in the search Action because ADR-0023 says this
     * class is *"the only class in the codebase permitted to query vessel
     * occupancy"*. A bulk read is still a read: routing around the port to make
     * it fast is how the third occupation shape gets forgotten in M2.
     *
     * The AVL-10 rule holds — an empty departure is not an occupation — and so
     * does AVL-7's buffer, applied per vessel because each may have its own.
     *
     * @param  Collection<int, Vessel>  $vessels
     * @return list<int> the ids of the vessels that are **not** free
     */
    public static function occupiedVesselIds(Collection $vessels, Window $window): array
    {
        if ($vessels->isEmpty()) {
            return [];
        }

        // Padded by the **largest** buffer in the set, so the scan is one query
        // and can only over-fetch; each vessel's own buffer then decides in PHP,
        // which is AVL-8's rule about never joining the buffer into the query.
        $buffer = (int) $vessels->max(
            static fn (Vessel $vessel): int => $vessel->effectiveTurnaroundBufferMinutes(),
        );

        $padded = $window->paddedBy($buffer);
        $ids = $vessels->map(static fn (Vessel $vessel): int => (int) $vessel->getKey())->all();

        $departures = Departure::query()
            ->whereIn('vessel_id', $ids)
            ->whereNot('status', DepartureStatus::Cancelled)
            ->where('starts_at_utc', '<', $padded->endUtc)
            ->where('ends_at_utc', '>', $padded->startUtc)
            ->get()
            // AVL-10: an empty departure occupies nothing.
            ->filter(static fn (Departure $departure): bool => $departure->seats_sold > 0 || $departure->seats_held > 0);

        $blocks = VesselBlock::query()
            ->whereIn('vessel_id', $ids)
            ->overlapping($padded)
            ->get();

        $occupied = [];

        foreach ($vessels as $vessel) {
            $own = $vessel->effectiveTurnaroundBufferMinutes();
            $key = (int) $vessel->getKey();

            $busy = $departures
                ->where('vessel_id', $key)
                ->contains(static fn (Departure $departure): bool => $window->conflictsWith(
                    Window::of($departure->starts_at_utc, $departure->ends_at_utc),
                    $own,
                ));

            $blocked = $blocks
                ->where('vessel_id', $key)
                ->contains(static fn (VesselBlock $block): bool => $window->conflictsWith($block->window(), $own));

            if ($busy || $blocked) {
                $occupied[] = $key;
            }
        }

        return $occupied;
    }

    /**
     * The conflict query itself — narrowed in SQL, decided in PHP.
     *
     * The range scan uses `departures_vessel_window_idx` over a window padded
     * by the buffer, which can only return more candidates than needed; the
     * exact AVL-7 predicate then runs in PHP. Doing it the other way — the
     * predicate in SQL — would need the buffer inside the query, and AVL-8 and
     * §7.2 both say the buffer is read once in the application and never joined
     * into these queries.
     *
     * @return Collection<int, Departure>
     */
    private static function departuresNear(Vessel $vessel, Window $window, ?Departure $excluding): Collection
    {
        $buffer = $vessel->effectiveTurnaroundBufferMinutes();
        $padded = $window->paddedBy($buffer);

        return Departure::query()
            ->where('vessel_id', $vessel->getKey())
            ->whereNot('status', DepartureStatus::Cancelled)
            ->where('starts_at_utc', '<', $padded->endUtc)
            ->where('ends_at_utc', '>', $padded->startUtc)
            ->when(
                $excluding?->exists,
                // AVL-9: an occupation never conflicts with itself.
                static fn ($query) => $query->whereKeyNot($excluding?->getKey()),
            )
            ->get()
            ->filter(static fn (Departure $departure): bool => $window->conflictsWith(
                Window::of($departure->starts_at_utc, $departure->ends_at_utc),
                $buffer,
            ))
            ->values();
    }
}
