<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Contracts\Channel;
use App\Enums\ChannelKey;
use App\Models\ChannelProductMap;
use App\Models\Product;

/**
 * EXT-1's "map external product ids", read direction.
 *
 * ## Why this is not on the `Channel` interface yet
 *
 * {@see Channel::productFor()} is where this belongs, and it
 * will be `GetYourGuideChannel`'s implementation of it. That class arrives with
 * the outbound half in a later issue, because an interface whose methods half
 * work is worse than one that is not there: a caller cannot tell "declines by
 * nature" from "not written yet", and {@see \App\Domain\Channels\Data\
 * ChannelResult} is deliberately unable to say the latter.
 *
 * The inbound endpoints need the lookup now, and they do not go through the
 * channel — they are controllers, because GetYourGuide's servers call us. So it
 * lives here, one small class, and moves behind the interface when the
 * interface can be satisfied honestly.
 *
 * ## Tenant scope is assumed, not asked for
 *
 * {@see ChannelProductMap} carries `BelongsToTenant`, and by the time anything
 * calls this the tenant is established — the Basic username resolved it before
 * the request reached a controller. Taking a tenant argument would invite a
 * caller to pass a different one, which is the cross-tenant defect the
 * isolation gate exists to catch.
 */
final class ChannelProducts
{
    /**
     * The trip behind one channel's product id, or null if it is not sold there.
     *
     * **Null is not "unavailable".** An operator maps the three trips they list
     * on GetYourGuide and leaves the rest alone, so an unmapped id is the
     * ordinary answer for most of the catalogue. A caller that conflated the
     * two would tell an OTA to keep asking about a product that will never
     * exist, or stop asking about one that is merely full today.
     */
    public function find(ChannelKey $channel, string $externalProductId): ?Product
    {
        if ($externalProductId === '') {
            return null;
        }

        $map = ChannelProductMap::query()
            ->where('channel', $channel->value)
            ->where('external_product_id', $externalProductId)
            ->first();

        if (! $map instanceof ChannelProductMap) {
            return null;
        }

        // Published or not is the caller's question, not this one's: a mapping
        // to an unpublished trip is still a mapping, and answering null here
        // would make "you withdrew it" indistinguishable from "you never mapped
        // it" in whatever the caller logs.
        return $map->product;
    }

    /** What this channel calls a trip of ours, if anything. */
    public function externalIdFor(ChannelKey $channel, Product $product): ?string
    {
        $map = ChannelProductMap::query()
            ->where('channel', $channel->value)
            ->where('product_id', $product->getKey())
            ->first();

        return $map?->external_product_id;
    }
}
