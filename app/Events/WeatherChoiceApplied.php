<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\WeatherChoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The weather-cancellation choice has been honoured (spec CXL-7).
 *
 * ## `automatic` is the field that matters
 *
 * CXL-7's deadline clause ends *"and the guest is notified"* — a guest who
 * never answered and has just been refunded by default is being told something
 * they did not ask for, and the email that says so cannot read like the one
 * confirming a choice they made. One event with a boolean rather than two
 * events, because everything else about the two messages is identical and two
 * events is how one of them stops being sent.
 *
 * **Nothing listens yet.** The email is #87.
 */
final class WeatherChoiceApplied
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
        public readonly WeatherChoice $choice,
        public readonly int $amountCents,
        public readonly bool $automatic,
    ) {}
}
