<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Booking\BookingReference;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Is this a booking reference a guest could have (spec BKG-3 item 5, CNV-11)?
 *
 * ADR-0007 asks for *"a validation rule object that reports errors in EL and
 * EN"* alongside the value object, and the split is deliberate:
 * {@see BookingReference} knows what a reference **is**, this knows what to say
 * when it is not.
 *
 * ## It validates the normalised form
 *
 * A guest typing `kai 7f3k2` into a support form has given a perfectly good
 * reference in a slightly wrong shape, and refusing it teaches them to distrust
 * a system that is about to find their booking anyway. So the rule normalises
 * first and judges what comes out — exactly what the lookup will do a moment
 * later, so the rule and the search can never disagree.
 *
 * ## The message shows an example rather than the alphabet
 *
 * "Thirty symbols excluding O, I, L and U" is true and useless. `KAI-7F3K2` is
 * a shape somebody can compare against what they are holding.
 */
class BookingReferenceFormat implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail($this->message());

            return;
        }

        if (BookingReference::tryFrom($value) === null) {
            $fail($this->message());
        }
    }

    private function message(): string
    {
        return (string) trans('booking.reference.invalid', [
            // Built from the configured prefix rather than hardcoded, so a
            // rename changes the example too. An example that shows a prefix
            // the system no longer uses is worse than no example.
            'example' => BookingReference::prefix() . '-7F3K2',
        ]);
    }
}
