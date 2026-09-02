<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Catalog\Actions\GuardVesselCapacity;
use App\Exceptions\CapacityLoweringRefused;
use App\Models\Vessel;
use App\Rules\VesselCapacityNotLowered;

/**
 * The writer-agnostic half of the `capacity_max` guard.
 *
 * {@see VesselCapacityNotLowered} covers the operator at a form and shows them
 * a Greek sentence beside the field. This covers everything else — an import, a
 * console command, an API write, a seeder — because `capacity_max` is a **legal**
 * ceiling and a boat that quietly shrank below a sold departure is discovered by
 * the port authority, not by a test.
 *
 * The same two-enforcement-points shape as #15's `TranslatableRequired` and
 * `MissingTranslationException`, and for the same stated reason: those two
 * disagreed about whitespace-only values until they were made to share an
 * implementation, and the import was the writer that could get the bad value in.
 * Both halves here delegate to {@see GuardVesselCapacity}.
 *
 * Registered on the model rather than in a provider so it cannot be forgotten
 * when the panel is not the thing doing the writing.
 */
final class VesselObserver
{
    /**
     * @throws CapacityLoweringRefused
     */
    public function saving(Vessel $vessel): void
    {
        // Only when the column is actually moving. Every other vessel edit —
        // renaming a boat, swapping its home port — must not pay for a fan-out
        // across products and departures.
        if (! $vessel->isDirty('capacity_max')) {
            return;
        }

        app(GuardVesselCapacity::class)($vessel, (int) $vessel->capacity_max);
    }
}
