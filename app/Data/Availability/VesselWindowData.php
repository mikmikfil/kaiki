<?php

declare(strict_types=1);

namespace App\Data\Availability;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\Window;
use App\Enums\AvailabilityRejection;
use Spatie\LaravelData\Data;

/**
 * One local date's answer for a whole-boat charter (spec AVL-30, AVL-31).
 *
 * The local start and end are rendered from the UTC window rather than echoed
 * from the request, so what a guest is shown is what was actually tested —
 * including on the October night when the same local time happens twice.
 */
final class VesselWindowData extends Data
{
    public function __construct(
        public readonly string $localDate,
        public readonly ?string $startsAtLocal,
        public readonly ?string $endsAtLocal,
        public readonly bool $available,
        public readonly ?int $priceFromCents = null,
        public readonly ?AvailabilityRejection $rejection = null,
    ) {}

    /**
     * Not called `from()`: `Spatie\LaravelData\Data::from()` already exists and
     * means something entirely different — build this object out of anything.
     */
    public static function forWindow(string $localDate, Window $window, bool $available, ?int $priceFromCents, ?AvailabilityRejection $rejection = null): self
    {
        $timezone = LocalDateTimeResolver::timezone();

        return new self(
            localDate: $localDate,
            startsAtLocal: LocalDateTimeResolver::localTime($window->startUtc, $timezone),
            endsAtLocal: LocalDateTimeResolver::localTime($window->endUtc, $timezone),
            available: $available,
            priceFromCents: $available ? $priceFromCents : null,
            rejection: $rejection,
        );
    }

    /** No window could even be built — an off-grid or out-of-hours proposal. */
    public static function refused(string $localDate, AvailabilityRejection $rejection): self
    {
        return new self($localDate, null, null, false, null, $rejection);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date' => $this->localDate,
            'starts_at_local' => $this->startsAtLocal,
            'ends_at_local' => $this->endsAtLocal,
            'available' => $this->available,
            'price_from_cents' => $this->priceFromCents,
            'reason' => $this->rejection?->value,
            'reason_message' => $this->rejection?->message(),
        ];
    }
}
