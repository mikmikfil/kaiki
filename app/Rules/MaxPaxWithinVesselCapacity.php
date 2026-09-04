<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Vessel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CAT-5: `max_pax` must not exceed the boat's `capacity_max`.
 *
 * **A legal ceiling, not a preference.** `vessels.capacity_max` is what the
 * boat is certified to carry, and a product that oversells it is discovered by
 * the port authority rather than by a test. The mirror of #16's
 * `VesselCapacityNotLowered`: that one stops a boat shrinking below what
 * products already promise, this one stops a product promising more than the
 * boat.
 *
 * Both numbers are named in the message, because "too many passengers" leaves
 * the operator to go and look up a figure they are already being judged
 * against.
 */
final class MaxPaxWithinVesselCapacity implements ValidationRule
{
    public function __construct(private readonly ?int $vesselId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A `quote` product may have no boat yet (§2.3), and a missing value is
        // the `required` rule's business rather than this one's.
        if ($this->vesselId === null || ! is_numeric($value)) {
            return;
        }

        $vessel = Vessel::query()->find($this->vesselId);

        if ($vessel === null) {
            return;
        }

        if ((int) $value > $vessel->capacity_max) {
            $fail('catalog.product.validation.max_pax_over_capacity')->translate([
                'max_pax' => (int) $value,
                'capacity' => $vessel->capacity_max,
                'vessel' => $vessel->name,
            ]);
        }
    }
}
