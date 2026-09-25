<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Channel;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Which outside system is selling, or occupying, the same boat (spec EXT-1,
 * per ADR-0034).
 *
 * ## The list is short because each case is a contract somebody signed
 *
 * Unlike {@see IntegrationProvider}, where a case is a vendor an operator may
 * pick from a dropdown, a case here is a *sales channel* with its own
 * commercial relationship and, for the OTAs, its own certification. Adding one
 * is not configuration; it is an implementation of {@see Channel}
 * and, where the shape differs, an ADR. ADR-0034 took GetYourGuide and left
 * Click&Boat unscoped for exactly that reason — no public supplier spec, and it
 * may turn out to be an iCal source rather than a channel at all.
 *
 * ## `Ical` is here to keep the interface honest
 *
 * EXT-1 named `IcalChannel` as the one implementation so the abstraction would
 * be shaped by a real caller before an OTA arrived. It is the weaker of the two
 * on purpose: it pulls occupancy and pushes nothing, which is what proves the
 * contract can express a channel that does not do everything, instead of one
 * built to fit GetYourGuide and bent afterwards.
 */
enum ChannelKey: string
{
    use HasTranslatedLabel;

    /** Somebody else's calendar, read into vessel blocks. One direction only. */
    case Ical = 'ical';

    /** GetYourGuide's supplier API. Their servers call ours (ADR-0034). */
    case GetYourGuide = 'getyourguide';

    /**
     * Does this channel's traffic arrive as calls *to* Kaiki?
     *
     * The distinction is not cosmetic. An inbound channel makes Kaiki's
     * reachability part of the operator's sales — if we are down, GetYourGuide
     * cannot sell at all — and it means availability is answered live from
     * `departures` rather than synchronised to a copy. ADR-0034 chose the
     * inbound shape precisely because a copy is where overlap comes from.
     */
    public function isInbound(): bool
    {
        return match ($this) {
            self::GetYourGuide => true,
            self::Ical => false,
        };
    }

    /**
     * Is this channel gated by the `channel_manager` flag (spec EXT-2)?
     *
     * iCal predates the flag and is not an OTA; switching it off would remove a
     * feature operators already depend on. The OTAs are off until their
     * certification passes.
     */
    public function needsChannelManagerFlag(): bool
    {
        return $this !== self::Ical;
    }
}
