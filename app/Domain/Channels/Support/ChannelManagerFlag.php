<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use Laravel\Pennant\Feature;

/**
 * The general switch that keeps the OTA channels shut (spec EXT-2, ADR-0034).
 *
 * ## Why there are two switches and not one
 *
 * `tenants.getyourguide_enabled` says *which operator* may sell through
 * GetYourGuide, and the platform admin sets it on Edit Merchant with a typed
 * reason that lands in that operator's own trail. This says *whether anybody
 * may at all*, and it is off until GetYourGuide's three-phase certification
 * passes.
 *
 * They are not redundant. The per-merchant column is an ordinary business
 * decision somebody makes a dozen times a year; this is a statement about
 * whether the integration is allowed to exist yet, and a mis-click on a merchant
 * screen must not be able to make it. ADR-0034 is explicit that the code ships
 * before the certification does, which is exactly the window in which one
 * careless click would start selling seats through a contract nobody has signed.
 *
 * ## It is stored, not read from the environment
 *
 * The temptation is an `.env` line, the way `kaiki.notifications.sms_enabled`
 * does it. Pennant was chosen instead (EXT-2, and the product owner's call on
 * 2026-09-21), so the value lives in the `features` table and is flipped
 * deliberately — `php artisan channels:manager open` — rather than by a deploy
 * that happens to carry a different environment file.
 *
 * That difference matters for this particular switch: an environment variable
 * is set by whoever deploys, and "has GetYourGuide certified us?" is not a
 * question the deploy pipeline can answer.
 *
 * ## The scope is explicitly global, and that is not a detail
 *
 * Pennant's default scope is the **authenticated user**. Left alone, this
 * feature would resolve and store one row per person who happened to be logged
 * in when it was first checked — and the answer to *"is this integration
 * certified"* would then depend on who was looking. It is asked from queued
 * jobs with nobody logged in, too.
 *
 * So every call here pins the scope to `null`, which Pennant stores under a
 * single reserved key. One row, one answer, for everybody.
 */
final class ChannelManagerFlag
{
    /** The feature's name in `features.name`, and in EXT-2's list. */
    public const NAME = 'channel_manager';

    /** May any OTA channel do anything at all? */
    public static function isOpen(): bool
    {
        return Feature::for(null)->active(self::NAME);
    }

    /**
     * Open it — after certification, and deliberately.
     *
     * Nothing calls this in the request path on purpose. It is an artisan
     * command and a console decision, because the thing it asserts is that a
     * third party has signed something off.
     */
    public static function open(): void
    {
        Feature::for(null)->activate(self::NAME);
    }

    public static function close(): void
    {
        Feature::for(null)->deactivate(self::NAME);
    }

    /**
     * Forget the stored value, so the definition's default answers again.
     *
     * For tests, and for the case where somebody wants the switch back in its
     * shipped state rather than in an explicitly-closed one. The two are the
     * same answer today and would not be if the default ever changed.
     */
    public static function forget(): void
    {
        Feature::for(null)->forget(self::NAME);
    }
}
