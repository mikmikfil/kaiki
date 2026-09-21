<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Channels\Channels\IcalChannel;
use App\Domain\Channels\Support\ChannelManagerFlag;
use App\Domain\Channels\Support\ChannelRegistry;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

/**
 * Wiring for the sales channels (spec EXT-1, per ADR-0034).
 *
 * ## A singleton, for the reason `VerifierRegistry` is one
 *
 * Registrations have to survive. A channel registered against a registry that
 * the container rebuilds on the next resolution is a channel that exists during
 * boot and nowhere else.
 *
 * ## iCal is here; GetYourGuide is not, yet
 *
 * {@see IcalChannel} is registered unconditionally: it predates both this
 * interface and the `channel_manager` flag, operators depend on it today, and
 * EXT-1 named it as the implementation that keeps the contract honest —
 * see its docblock for the two methods it declines and why that is the useful
 * part.
 *
 * GetYourGuide arrives with its own issue, **behind the `channel_manager` flag
 * (EXT-2), default off until their certification passes** (ADR-0034). Until
 * then {@see ChannelRegistry::for()} answers a `NullChannel` for it, which
 * declines every call with a visible, non-retryable «not configured» rather
 * than throwing — a seat movement must never fail because a channel nobody
 * enabled could not be told about it.
 *
 * `ChannelCoverageTest` records which keys are live, so the gap between the
 * enum's cases and their implementations stays visible in the suite instead of
 * being rediscovered from a channel that quietly never sold anything.
 */
class ChannelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class, function (): ChannelRegistry {
            $registry = new ChannelRegistry;

            // Resolved lazily through the container so that constructing the
            // registry does not construct an HTTP client — the same reason the
            // verifier registry resolves rather than instantiates.
            $registry->register($this->app->make(IcalChannel::class));

            return $registry;
        });
    }

    public function boot(): void
    {
        /*
         * EXT-2's `channel_manager`, the first of those nine flags to be wired.
         *
         * **The default is false, and the default is the whole point.** A fresh
         * database, a new deployment and a restored backup all arrive with the
         * OTA channels shut, and opening them is a deliberate act somebody
         * performs after GetYourGuide's certification passes — not a state
         * something can drift into.
         *
         * Defined in `boot()` rather than `register()` because a definition is
         * not a binding: it runs when the feature is first resolved, and at
         * that point it may want anything the container has.
         */
        Feature::define(ChannelManagerFlag::NAME, fn (): bool => false);
    }
}
