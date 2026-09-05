<?php

declare(strict_types=1);

namespace App\Data\Availability;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Enums\AvailabilityRejection;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use Illuminate\Support\Carbon;
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
 *
 * ## The window is carried both ways, and the UTC half is not decoration
 *
 * `docs/api.md`'s `LocalWindow` requires `local_time` **and** `starts_at`, and
 * says why: the client renders the local strings verbatim and uses the instants
 * only for countdowns and sorting, so it never converts a timezone and never
 * gets DST wrong. Deriving the instant in the resource instead would mean
 * re-doing in the HTTP layer the one conversion ADR-0016 put in
 * {@see LocalDateTimeResolver} — and doing it from a
 * date and a wall-clock string, which is exactly the arithmetic that breaks on
 * the two nights a year it matters.
 *
 * `capacity` and `minPax` come along for the same reason: the API's
 * `DepartureOption` requires both, and re-reading the departures in the
 * controller to find them would be a second query over rows the engine has
 * already held.
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
        public readonly ?string $localDate = null,
        public readonly ?Carbon $startsAtUtc = null,
        public readonly ?Carbon $endsAtUtc = null,
        public readonly bool $dstAmbiguous = false,
        public readonly int $capacity = 0,
        public readonly int $minPax = 0,
    ) {}

    /**
     * How many more capacity-counting passengers would guarantee this sailing
     * (`DepartureOption.seats_to_guarantee`).
     *
     * Null when it is already guaranteed, and null when `min_pax` is zero —
     * which means "always guaranteed", so there is no shortfall to report. A
     * zero would read as "one more and we sail", which is a different promise.
     */
    public function seatsToGuarantee(): ?int
    {
        if ($this->isGuaranteed || $this->minPax <= 0) {
            return null;
        }

        return max(0, $this->minPax - max(0, $this->capacity - $this->seatsRemaining));
    }

    public static function available(Departure $departure, int $seatsRemaining, ?int $priceFromCents): self
    {
        return new self(
            uuid: $departure->uuid,
            localTime: (string) $departure->local_time,
            available: true,
            seatsRemaining: $seatsRemaining,
            isGuaranteed: $departure->status === DepartureStatus::Guaranteed,
            priceFromCents: $priceFromCents,
            localDate: $departure->local_date->toDateString(),
            startsAtUtc: $departure->starts_at_utc,
            endsAtUtc: $departure->ends_at_utc,
            dstAmbiguous: $departure->dst_ambiguous,
            capacity: $departure->capacity,
            minPax: $departure->min_pax,
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
            localDate: $departure->local_date->toDateString(),
            startsAtUtc: $departure->starts_at_utc,
            endsAtUtc: $departure->ends_at_utc,
            dstAmbiguous: $departure->dst_ambiguous,
            capacity: $departure->capacity,
            minPax: $departure->min_pax,
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
