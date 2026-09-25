<?php

declare(strict_types=1);

namespace App\Data\Availability;

use App\Enums\DepartureCancelReason;
use App\Models\Product;

/**
 * One line of the departures calendar: a sailing, or a whole-boat charter day.
 *
 * `status` is the word the guest reads, decided once by the service so the
 * page and the API cannot tell the same departure two different ways.
 */
final class DepartureCalendarRow
{
    public const KIND_DEPARTURE = 'departure';

    /** A `per_vessel` trip: no times, the whole boat for the day. */
    public const KIND_CHARTER = 'charter';

    /** Room for this party, and plenty of it. */
    public const AVAILABLE = 'available';

    /** Room for this party, and few seats left (see `kaiki.hosted.calendar.few_seats`). */
    public const FEW = 'few';

    /** Some seats left, but fewer than the party. */
    public const NO_FIT = 'no_fit';

    /** The trip takes at most `limit` people (CAT-5), fewer than asked. */
    public const TOO_MANY = 'too_many';

    /** The trip sails only for `limit` people or more (CAT-5). */
    public const TOO_FEW = 'too_few';

    public const FULL = 'full';

    /** A charter day on which the boat is already taken. */
    public const BOOKED = 'booked';

    public const CANCELLED = 'cancelled';

    /** Left already, today. */
    public const PAST = 'past';

    /** Still to leave, but inside the operator's lead time (AVL-19). */
    public const CLOSED = 'closed';

    /** Beyond the operator's advance window (AVL-20). */
    public const LATER = 'later';

    /** A `quote` trip (BKG-24): asked about, not booked. */
    public const ON_REQUEST = 'on_request';

    public function __construct(
        public readonly string $kind,
        public readonly Product $product,
        public readonly string $localDate,
        public readonly ?string $localTime,
        public readonly ?string $departureUuid,
        public readonly string $status,
        public readonly int $seatsAvailable = 0,
        public readonly ?int $priceCents = null,
        public readonly ?DepartureCancelReason $cancelReason = null,
        public readonly ?string $vesselName = null,
        public readonly bool $isGuaranteed = false,
        /** The party bound behind `too_many` / `too_few`. */
        public readonly ?int $limit = null,
    ) {}

    /** Can the guest press «Κράτηση» on it? */
    public function isBookable(): bool
    {
        return $this->status === self::AVAILABLE || $this->status === self::FEW;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function isWeather(): bool
    {
        return $this->status === self::CANCELLED && $this->cancelReason === DepartureCancelReason::Weather;
    }

    /** Is the price worth printing? Only where the guest could still act on it. */
    public function showsPrice(): bool
    {
        return $this->priceCents !== null
            && in_array($this->status, [self::AVAILABLE, self::FEW, self::NO_FIT], true);
    }

    /** What the price is for: one seat, or the whole boat. */
    public function priceUnit(): string
    {
        return $this->kind === self::KIND_CHARTER ? 'boat' : 'person';
    }
}
