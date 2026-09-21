<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Contracts\Channel;
use App\Domain\Channels\Channels\NullChannel;
use App\Domain\Channels\Data\ChannelResult;
use App\Domain\Integrations\Support\VerifierRegistry;
use App\Enums\ChannelKey;

/**
 * Which {@see Channel} answers for which key (spec EXT-1, per ADR-0034).
 *
 * The same shape as {@see VerifierRegistry},
 * and for the same reason: the channels arrive one issue at a time, and a
 * `match` in a caller is a file every later issue has to remember to edit. A
 * registration is one line in a service provider, beside the channel being
 * introduced.
 *
 * ## An unregistered channel gets a `NullChannel`, not an exception
 *
 * {@see self::for()} never returns null. A caller asking for GetYourGuide on a
 * build where it is not registered — because the `channel_manager` flag is off,
 * which is its state until certification passes — gets a channel that declines
 * every call with {@see ChannelResult::notConfigured()}.
 *
 * That choice matters more than it looks. The callers are a queued push after a
 * seat moves and a nightly reconciliation, and neither has any business
 * throwing because the platform has a feature switched off. A seat movement must
 * never fail because a channel nobody enabled could not be told about it.
 * {@see self::has()} is there for the caller that genuinely wants to know.
 */
final class ChannelRegistry
{
    /** @var array<string, Channel> */
    private array $channels = [];

    public function register(Channel $channel): void
    {
        // The channel names itself. A key passed alongside would be a second
        // source of truth, and the two would eventually disagree.
        $this->channels[$channel->key()->value] = $channel;
    }

    /** Never null: an unregistered key answers with a declining channel. */
    public function for(ChannelKey $key): Channel
    {
        return $this->channels[$key->value] ?? new NullChannel($key);
    }

    public function has(ChannelKey $key): bool
    {
        return isset($this->channels[$key->value]);
    }

    /**
     * The channels that are actually wired on this build.
     *
     * Read by a test that records which are live, so the gap between the enum's
     * cases and their implementations stays visible in the suite — the same job
     * `NoPersonalDataInAuditContextTest` does for `AuditAction`.
     *
     * @return list<ChannelKey>
     */
    public function registered(): array
    {
        return array_values(array_filter(
            ChannelKey::cases(),
            fn (ChannelKey $key): bool => $this->has($key),
        ));
    }
}
