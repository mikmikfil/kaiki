<?php

declare(strict_types=1);

namespace App\Domain\Channels\Channels;

use App\Contracts\Channel;
use App\Domain\Availability\Actions\SyncIcalSource;
use App\Domain\Channels\Data\ChannelResult;
use App\Enums\ChannelKey;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\IcalSource;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;

/**
 * Somebody else's calendar, as a channel (spec EXT-1, OPS-13, OPS-15; ADR-0034).
 *
 * ## This existed before the interface did, and that is the point
 *
 * EXT-1 required `IcalChannel` as the first implementation so the abstraction
 * would be shaped by a caller that already worked, before GetYourGuide arrived
 * to shape it instead. Nothing here is new behaviour: it is
 * {@see SyncIcalSource}, which has pulled external calendars into `VesselBlock`
 * rows since #29, reached through the contract.
 *
 * Wrapping rather than moving is deliberate. `SyncIcalSourceJob` and the
 * operator's «Συγχρονισμός τώρα» button still call the action directly, and
 * should: the action is the unit of work with the retry policy and the
 * one-sided deletion guard on it. This class is the channel-shaped door onto
 * the same room.
 *
 * ## It declines two of the four, and the declines are the interesting part
 *
 * **Pushing availability is not applicable.** An iCal feed is a URL somebody
 * else fetches on their own schedule — `IcalFeed` serves it, and the far side
 * decides when to look. There is nobody to tell. Reporting success here would
 * be worse than useless, because the reconciliation would take it as evidence
 * the far side is current when it may be a day behind its own cron.
 *
 * **Acknowledging a cancellation is not applicable** for the same reason, one
 * step further on: a calendar has no notion of us having read it.
 *
 * **And products do not exist in a calendar.** An iCal source is attached to a
 * *vessel*, not a trip — the event says the hull is out, never which trip
 * somebody else sold. So {@see self::productFor()} is `null` always, not as a
 * lookup miss but because the question does not apply. That is the shape a
 * per-vessel channel has, and an interface that could not express it would be
 * an interface built for GetYourGuide alone.
 */
final class IcalChannel implements Channel
{
    public function __construct(private readonly SyncIcalSource $sync) {}

    public function key(): ChannelKey
    {
        return ChannelKey::Ical;
    }

    public function pushAvailability(Departure $departure): ChannelResult
    {
        return ChannelResult::notApplicable(
            'An iCal feed is fetched from Kaiki on the reader\'s own schedule; there is nothing to push to.',
        );
    }

    /**
     * Pull every active source this operator has, and report what moved.
     *
     * The sources are *not* filtered by `dueForSync` here. That scope paces the
     * scheduled job so a fifteen-minute tick does not hammer somebody's server;
     * a caller reaching for the channel is asking for the current answer now,
     * which is the same thing the operator's own button does.
     *
     * **A failure on one source does not abandon the rest.** Calendars fail
     * independently — one host is down, another's certificate expired — and a
     * loop that stopped at the first would leave a boat's other bookings
     * unknown because an unrelated feed was unreachable.
     */
    public function pullBookings(Tenant $tenant): ChannelResult
    {
        $changed = 0;
        $failed = 0;

        Tenancy::forTenant($tenant, function () use (&$changed, &$failed): void {
            foreach (IcalSource::query()->where('is_active', true)->get() as $source) {
                $result = ($this->sync)($source);

                if ($result->failed) {
                    $failed++;

                    continue;
                }

                $changed += $result->created + $result->updated + $result->removed;
            }
        });

        if ($failed > 0) {
            // Partial success is still a failure to report: the blocks that did
            // not arrive are the ones that would have stopped a sale.
            return ChannelResult::failed(
                messageKey: 'channels.ical.sources_failed',
                messageArguments: ['count' => $failed],
            );
        }

        return ChannelResult::done(affected: $changed);
    }

    public function productFor(Tenant $tenant, string $externalProductId): ?Product
    {
        // An iCal source belongs to a vessel. A calendar event says the hull is
        // out; it never says which trip somebody else sold.
        return null;
    }

    public function acknowledgeCancellation(Booking $booking): ChannelResult
    {
        return ChannelResult::notApplicable(
            'A calendar has no notion of having been read, so there is nothing to acknowledge to.',
        );
    }
}
