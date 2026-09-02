<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Catalog\Actions\GuardVesselCapacity;
use App\Exceptions\CapacityLoweringRefused;
use App\Models\Vessel;
use App\Observers\VesselObserver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `capacity_max` may not drop below what has already been promised.
 *
 * The same requirement {@see VesselObserver} throws on, stated where an
 * operator can act on it: beside the field, in their own language, naming the
 * records in the way — because "you cannot do that" without saying what is
 * stopping them leaves them guessing which of forty departures to look at.
 *
 * Both halves delegate to {@see GuardVesselCapacity} and both render through
 * {@see CapacityLoweringRefused::message()}, so the form and the import cannot
 * disagree about what counts as an offender or how it is worded.
 *
 * Attached only on **edit**; a vessel being created has promised nothing.
 */
final class VesselCapacityNotLowered implements ValidationRule
{
    public function __construct(private readonly Vessel $vessel) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            // Not this rule's business — the integer rule on the field reports
            // a non-numeric value, and duplicating it here would show the
            // operator two errors for one mistake.
            return;
        }

        $requested = (int) $value;

        $claims = app(GuardVesselCapacity::class)->claims($this->vessel, $requested);

        if ($claims !== []) {
            $fail(CapacityLoweringRefused::message($requested, $claims));
        }
    }
}
