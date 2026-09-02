<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Contracts\VesselCapacityClaims;
use App\Domain\Catalog\Data\CapacityClaim;
use App\Exceptions\CapacityLoweringRefused;
use App\Models\Vessel;
use App\Providers\AppServiceProvider;

/**
 * Refuses to shrink a boat below what has already been promised (`docs/data-model.md`
 * §2.3: *"Lowering `capacity_max` below a live departure's `capacity` is blocked
 * by the application with a list of offending departures"*).
 *
 * The constraint spans `vessels`, `products` and `departures`, so no database
 * can express it and no single table owns it. This Action is where it lives:
 * both the form rule and the model observer call it, so a person and an import
 * get the same answer.
 *
 * ## Only lowering
 *
 * Raising `capacity_max`, or leaving it alone, is never refused. A bigger boat
 * cannot invalidate a smaller promise, and checking anyway would put a fan-out
 * across three tables on the path of every unrelated vessel edit.
 *
 * ## The sources
 *
 * Discovered from the container tag registered in {@see AppServiceProvider},
 * **not** by querying tables here. Today the tag is empty and this Action
 * therefore refuses nothing, which is correct: `products` (#18) and
 * `departures` (#23) do not exist, so nothing can have claimed a seat.
 */
final class GuardVesselCapacity
{
    /** The container tag every {@see VesselCapacityClaims} implementation binds to. */
    public const TAG = 'vessel.capacity_claims';

    /**
     * @param  iterable<VesselCapacityClaims>  $sources
     */
    public function __construct(private readonly iterable $sources) {}

    /**
     * @throws CapacityLoweringRefused
     */
    public function __invoke(Vessel $vessel, int $newCapacity): void
    {
        $claims = $this->claims($vessel, $newCapacity);

        if ($claims !== []) {
            throw CapacityLoweringRefused::forClaims($newCapacity, $claims);
        }
    }

    /**
     * Everything standing in the way, or an empty list.
     *
     * Separate from {@see self::__invoke()} because the form rule needs the list
     * without the exception — a validation failure is a message beside a field,
     * not a thrown error. One collection routine, two presentations.
     *
     * @return list<CapacityClaim>
     */
    public function claims(Vessel $vessel, int $newCapacity): array
    {
        // A new vessel has promised nothing, and a raise cannot break a promise.
        // `getOriginal()` rather than the current attribute, because by the time
        // a `saving` observer runs the model already carries the new value.
        if (! $vessel->exists) {
            return [];
        }

        $current = $vessel->getOriginal('capacity_max');

        if (! is_numeric($current) || $newCapacity >= (int) $current) {
            return [];
        }

        $claims = [];

        foreach ($this->sources as $source) {
            foreach ($source->exceeding($vessel, $newCapacity) as $claim) {
                $claims[] = $claim;
            }
        }

        // Biggest offender first: an operator who has to raise the number back
        // needs the one that decides the floor, and a list ordered by whichever
        // source happened to be registered first buries it.
        usort($claims, static fn (CapacityClaim $a, CapacityClaim $b): int => $b->pax <=> $a->pax);

        return $claims;
    }
}
