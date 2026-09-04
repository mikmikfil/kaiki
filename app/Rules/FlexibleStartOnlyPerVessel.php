<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\BookingMode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CAT-5: `flexible_start` is settable on `per_vessel` alone.
 *
 * A shared departure has one start time by definition — that is what makes it
 * shared. A guest choosing their own would not be joining that departure, it
 * would be a different one, and the availability engine has no way to express
 * the difference. A `quote` product has no computed availability at all.
 *
 * Runs even when the value is absent (`implicit`), because "false" and "not
 * submitted" must behave the same and Laravel skips a non-implicit rule on a
 * falsy value — the exact bug #16 found in `TranslatableRequired`.
 */
final class FlexibleStartOnlyPerVessel implements ValidationRule
{
    public bool $implicit = true;

    public function __construct(private readonly ?BookingMode $mode) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->isTruthy($value)) {
            return;
        }

        if ($this->mode === null || $this->mode->allowsFlexibleStart()) {
            return;
        }

        $fail('catalog.product.validation.flexible_start_mode')->translate([
            'mode' => $this->mode->label(),
        ]);
    }

    /** Checkbox state arrives as "1", 1, true or "on" depending on the caller. */
    private function isTruthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }
}
