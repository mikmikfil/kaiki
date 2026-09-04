<?php

declare(strict_types=1);

namespace App\Data\Availability;

use App\Enums\AvailabilityRejection;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use Spatie\LaravelData\Data;

/**
 * One departure's answer (spec AVL-29, AVL-22).
 *
 * Carries the departure **uuid** rather than its id, because this crosses into
 * a public API and §1.1 keeps sequential ids off the wire.
 *
 * ## An unavailable departure is still returned, with its reason
 *
 * Except a cancelled one, which AVL-28 removes entirely. The difference matters
 * to the widget: a date with a full boat should render differently from a date
 * with no sailing at all, and a guest told "sold out" will come back, while a
 * guest shown a blank calendar concludes the operator has stopped running.
 */
final class DepartureAvailabilityData extends Data
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $localTime,
        public readonly bool $available,
        public readonly int $seatsRemaining,
        public readonly bool $isGuaranteed,
        public readonly ?int $priceFromCents = null,
        public readonly ?AvailabilityRejection $rejection = null,
    ) {}

    public static function available(Departure $departure, int $seatsRemaining, ?int $priceFromCents): self
    {
        return new self(
            uuid: $departure->uuid,
            localTime: (string) $departure->local_time,
            available: true,
            seatsRemaining: $seatsRemaining,
            isGuaranteed: $departure->status === DepartureStatus::Guaranteed,
            priceFromCents: $priceFromCents,
        );
    }

    public static function unavailable(Departure $departure, AvailabilityRejection $rejection, int $seatsRemaining = 0): self
    {
        return new self(
            uuid: $departure->uuid,
            localTime: (string) $departure->local_time,
            available: false,
            seatsRemaining: max(0, $seatsRemaining),
            isGuaranteed: $departure->status === DepartureStatus::Guaranteed,
            rejection: $rejection,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'departure_uuid' => $this->uuid,
            'local_time' => $this->localTime,
            'available' => $this->available,
            'seats_remaining' => $this->seatsRemaining,
            'is_guaranteed' => $this->isGuaranteed,
            'price_from_cents' => $this->priceFromCents,
            'reason' => $this->rejection?->value,
            'reason_message' => $this->rejection?->message(),
        ];
    }
}
