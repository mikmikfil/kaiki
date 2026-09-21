<?php

declare(strict_types=1);

namespace App\Domain\Channels\Channels;

use App\Contracts\Channel;
use App\Domain\Channels\Data\ChannelResult;
use App\Domain\Compliance\Gateways\NullMyDataGateway;
use App\Domain\Notifications\Gateways\NullSmsGateway;
use App\Enums\ChannelKey;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;

/**
 * The channel for an operator who has connected none.
 *
 * ## It declines, and it says which kind of decline it is
 *
 * {@see NullSmsGateway} swallows a message
 * and reports success, because an unsent text is a small loss beside a broken
 * reminder sweep. {@see NullMyDataGateway}
 * refuses outright, because an invoice that claims to be filed and is not is
 * discovered by an auditor.
 *
 * This one sits with myDATA, for a related reason: **the thing at stake is a
 * seat**. If an unconfigured channel reported success, the nightly
 * reconciliation would conclude the far side agrees with us about what is sold,
 * and the one job whose entire purpose is to notice a disagreement would be the
 * job guaranteeing there is none.
 *
 * So every method answers {@see ChannelResult::notConfigured()} — visible, not
 * retryable, and honest — and {@see self::productFor()} answers `null`, which
 * an OTA-facing caller reads as *this product is not sold here*.
 *
 * ## Which key it reports, and why that is a question at all
 *
 * There is no honest answer, so it reports the channel it stands in for. It is
 * constructed with one; nothing resolves a `NullChannel` without knowing which
 * channel it is substituting for, because the registry resolves *by key*.
 */
final class NullChannel implements Channel
{
    public function __construct(private readonly ChannelKey $key) {}

    public function key(): ChannelKey
    {
        return $this->key;
    }

    public function pushAvailability(Departure $departure): ChannelResult
    {
        return ChannelResult::notConfigured();
    }

    public function pullBookings(Tenant $tenant): ChannelResult
    {
        return ChannelResult::notConfigured();
    }

    public function productFor(Tenant $tenant, string $externalProductId): ?Product
    {
        // Not "unavailable" — not sold here at all. Nothing is mapped, because
        // nothing is connected.
        return null;
    }

    public function acknowledgeCancellation(Booking $booking): ChannelResult
    {
        return ChannelResult::notConfigured();
    }
}
