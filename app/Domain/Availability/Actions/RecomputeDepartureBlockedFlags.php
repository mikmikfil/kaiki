<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\Support\Window;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Illuminate\Support\Collection;

/**
 * Keep `departures.is_blocked` true (`docs/data-model.md` §2.4, brief §5 rule 1).
 *
 * ## The flag is a cache, and saying so matters
 *
 * §2.4: *"`is_blocked` is a **cache of a range query**, not a source of truth.
 * The availability service still re-checks `vessel_blocks` on the write path."*
 * It exists so the read path and the panel calendar do not have to join, and
 * drift is caught by the nightly reconciler.
 *
 * Which means a bug here costs a wrong badge and a slightly wrong list, not an
 * overbooked boat — and it is the reason this recomputes rather than
 * incrementally toggles. Toggling would be faster and would drift.
 *
 * ## It never cancels anything
 *
 * A block landing on a departure that has already sold seats raises an
 * **operator alert**, not a cancellation. §2.4 and brief §5 are explicit, and
 * the reason is obvious once stated: a maintenance window typed with the wrong
 * month would otherwise silently cancel a boat full of paying guests. The
 * operator decides; the flag and the alert give them what they need to.
 */
final class RecomputeDepartureBlockedFlags
{
    /**
     * Recompute for every departure the block touches, and report the sold ones.
     *
     * @return Collection<int, Departure> departures with seats that are now blocked
     */
    public function __invoke(VesselBlock $block): Collection
    {
        $vessel = $block->vessel;

        if (! $vessel instanceof Vessel) {
            return collect();
        }

        return $this->forVesselWindow($vessel, $block->window());
    }

    /**
     * Recompute across a window — the shape a delete needs, since the block is
     * gone by then and only its former window is known.
     *
     * @return Collection<int, Departure>
     */
    public function forVesselWindow(Vessel $vessel, Window $window): Collection
    {
        $buffer = $vessel->effectiveTurnaroundBufferMinutes();
        $padded = $window->paddedBy($buffer);

        $departures = Departure::query()
            ->where('vessel_id', $vessel->getKey())
            ->whereNot('status', DepartureStatus::Cancelled)
            ->where('starts_at_utc', '<', $padded->endUtc)
            ->where('ends_at_utc', '>', $padded->startUtc)
            ->get();

        // One query for every block that could cover any of them, rather than
        // one per departure: the whole point of the flag is that the read path
        // is cheap, and a recompute that was an N+1 would move the cost rather
        // than remove it.
        $blocks = VesselBlock::query()
            ->forVessel($vessel->getKey())
            ->overlapping($padded)
            ->get();

        $affected = collect();

        foreach ($departures as $departure) {
            $departureWindow = Window::of($departure->starts_at_utc, $departure->ends_at_utc);

            $blocked = $blocks->contains(
                static fn (VesselBlock $candidate): bool => $departureWindow->conflictsWith($candidate->window(), $buffer),
            );

            if ($departure->is_blocked !== $blocked) {
                // `saveQuietly`: this is a derived flag, and bumping
                // `updated_at` on a departure nobody edited would make the
                // column useless for the question it exists to answer.
                $departure->is_blocked = $blocked;
                $departure->saveQuietly();
            }

            if ($blocked && ($departure->seats_sold > 0 || $departure->seats_held > 0)) {
                $affected->push($departure);
            }
        }

        return $affected->values();
    }
}
