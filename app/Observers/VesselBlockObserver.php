<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Availability\Actions\RecomputeDepartureBlockedFlags;
use App\Models\VesselBlock;

/**
 * Keeps `departures.is_blocked` in step with the blocks (§2.4).
 *
 * On the model rather than in a provider, because the panel is not the only
 * writer: the iCal sync creates and deletes these by the dozen in M5, and a
 * flag that only the panel maintained would be wrong for exactly the blocks an
 * operator did not type themselves.
 *
 * The delete hook recomputes from the **former** window, captured before the
 * row goes: after deletion there is nothing to ask, and the departures that
 * were blocked by it are precisely the ones whose flag must now come off.
 */
final class VesselBlockObserver
{
    public function saved(VesselBlock $block): void
    {
        app(RecomputeDepartureBlockedFlags::class)($block);
    }

    public function deleted(VesselBlock $block): void
    {
        $vessel = $block->vessel;

        if ($vessel !== null) {
            // The row is gone; its window is not, because the model instance
            // still holds it.
            app(RecomputeDepartureBlockedFlags::class)->forVesselWindow($vessel, $block->window());
        }
    }
}
