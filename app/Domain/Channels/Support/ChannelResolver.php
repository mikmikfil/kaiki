<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Contracts\Channel;
use App\Domain\Channels\Channels\NullChannel;
use App\Enums\ChannelKey;
use App\Models\Tenant;

/**
 * The one door anything goes through to reach a channel (ADR-0034).
 *
 * ## Three locks, and all three are asked here
 *
 * A channel runs for an operator only when all of these hold:
 *
 *  1. **The platform allows it at all** — {@see ChannelManagerFlag}, shut until
 *     GetYourGuide's certification passes.
 *  2. **This operator has it switched on** — `tenants.getyourguide_enabled`,
 *     set on Edit Merchant with a typed reason in their own audit trail.
 *  3. **The channel is actually implemented** — {@see ChannelRegistry}.
 *
 * Asking them in one place is the point. `SendNotification::smsEnabled()` is
 * the same arrangement for the SMS pair, and it exists for the same reason:
 * when a kill switch is checked at three call sites, the fourth call site is
 * the one somebody adds later without checking anything.
 *
 * ## Failing shut, never open
 *
 * Every refusal returns a {@see NullChannel}, which declines each method with a
 * visible, non-retryable «not configured». Nothing throws.
 *
 * That is deliberate and it is the opposite of what a security-shaped
 * instinct suggests. The callers are a queued push after a seat moved and a
 * nightly reconciliation — a booking must not fail because a channel nobody
 * enabled could not be told about it. Refusing loudly here would mean the
 * platform's own kill switch could take down bookings, which is a worse outage
 * than the thing it protects against.
 *
 * ## iCal is not gated
 *
 * It predates both locks and operators depend on it today. `ChannelKey`
 * answers that question ({@see ChannelKey::needsChannelManagerFlag()}) rather
 * than this class carrying a special case, so a future channel declares its own
 * answer instead of being forgotten here.
 */
final class ChannelResolver
{
    public function __construct(private readonly ChannelRegistry $registry) {}

    /**
     * The channel this operator may actually use, or one that declines.
     *
     * Never null, and never throws — see the class docblock for why refusing
     * loudly would be the more dangerous design.
     */
    public function for(Tenant $tenant, ChannelKey $key): Channel
    {
        if (! $this->isLiveFor($tenant, $key)) {
            return new NullChannel($key);
        }

        return $this->registry->for($key);
    }

    /**
     * May this operator use this channel — as a matter of permission?
     *
     * The two switches only: the platform's flag and the operator's own column.
     * It deliberately does **not** ask whether the channel is implemented yet.
     *
     * That distinction is the useful one, and getting it wrong would hide a
     * screen. An operator pastes their credentials *before* anything can use
     * them — Viva's connection screen existed for weeks before there was a
     * verifier to press — so the question a form asks is "are you allowed to
     * set this up", not "does the client exist". Asking the latter would mean
     * the GetYourGuide card appears only in the release that makes its first
     * outbound call, which is backwards: by then the operator should already
     * have entered the credentials that call needs.
     */
    public function isPermittedFor(Tenant $tenant, ChannelKey $key): bool
    {
        if ($key->needsChannelManagerFlag() && ! ChannelManagerFlag::isOpen()) {
            return false;
        }

        return match ($key) {
            // Not gated per operator: an operator's own calendars are theirs.
            ChannelKey::Ical => true,
            ChannelKey::GetYourGuide => $tenant->usesGetYourGuide(),
        };
    }

    /**
     * Is this channel permitted *and* built, so calling it would do something?
     *
     * What {@see self::for()} asks before handing back a real channel. Kept
     * separate from {@see self::isPermittedFor()} because a channel that is
     * allowed but not yet implemented is a perfectly ordinary state — it is the
     * state GetYourGuide is in between this issue and the one that builds its
     * endpoints — and the two questions have different right answers in it.
     */
    public function isLiveFor(Tenant $tenant, ChannelKey $key): bool
    {
        return $this->registry->has($key) && $this->isPermittedFor($tenant, $key);
    }
}
