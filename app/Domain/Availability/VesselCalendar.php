<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Availability\Support\Window;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Vessel;
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
 * **Today this class knows about one shape.** `vessel_blocks` arrives with #29
 * and per-vessel booking windows in M2; both land here rather than at a call
 * site, and every caller written against this port gains them for free.
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
        return self::occupationsFor($vessel, $window, $excluding)->isEmpty();
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
