<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Support;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * BKG-18, and the half of it that gets skipped (RESOLVED).
 *
 * > *Reminder jobs are scheduled with the tenant timezone and are not sent
 * > between 21:00 and 08:00 local; a reminder that would fall in that window is
 * > delivered at 08:00, **unless doing so would place it after the event it
 * > warns about, in which case it is dropped and logged**.*
 *
 * ## The first half is easy and the second half is the requirement
 *
 * Deferring to 08:00 takes four lines. Noticing that 08:00 is *after the
 * departure being warned about*, and dropping instead, is the part everybody
 * leaves out — and its failure mode is a *"your trip is tomorrow"* text
 * message arriving while the guest is standing on the boat.
 *
 * The requirement's own note gives the reason for the first half: **an SMS at
 * 03:00 is a support incident.** The reason for the second is worse than a
 * support incident; it is a message that makes the platform look like it does
 * not know what day it is.
 *
 * ## Local, and therefore not a subtraction on a UTC instant
 *
 * "21:00 to 08:00" is a wall-clock window in the **tenant's** timezone, so the
 * question is asked through {@see LocalDateTimeResolver} rather than by
 * comparing hours on a UTC `Carbon`. In Athens that is a three-hour error in
 * summer and two in winter — which is the difference between 08:00 and 05:00,
 * and 05:00 is squarely inside the window this exists to protect.
 *
 * ## The result is a decision, not a time
 *
 * {@see self::decide()} answers with a {@see QuietHoursDecision} rather than a
 * nullable Carbon, because "send now", "send at 08:00" and "drop it" are three
 * outcomes and a null cannot carry a reason to the log.
 */
final class QuietHours
{
    /** The window's edges, in tenant-local wall-clock hours (BKG-18). */
    public const OPENS_AT_HOUR = 8;

    public const CLOSES_AT_HOUR = 21;

    /**
     * When — or whether — this reminder may be delivered.
     *
     * @param  Carbon  $at  the instant the scheduler wanted to send
     * @param  Carbon|null  $eventAt  what the message warns about; null when it
     *                                warns about nothing and can never be stale
     */
    public static function decide(?Tenant $tenant, Carbon $at, ?Carbon $eventAt = null): QuietHoursDecision
    {
        $timezone = LocalDateTimeResolver::timezone($tenant);
        $local = $at->copy()->setTimezone($timezone);

        if (! self::isQuiet($local)) {
            return QuietHoursDecision::sendAt($at);
        }

        $openAt = self::nextOpening($local)->utc();

        // **The half that gets skipped.** A reminder deferred past the thing it
        // warns about is worse than no reminder: the guest is on the boat.
        if ($eventAt !== null && $openAt->greaterThanOrEqualTo($eventAt)) {
            return QuietHoursDecision::drop();
        }

        return QuietHoursDecision::sendAt($openAt);
    }

    /** Is this local wall-clock time inside 21:00–08:00? */
    public static function isQuiet(Carbon $local): bool
    {
        $hour = (int) $local->format('G');

        // The window wraps midnight, so it is a union rather than a range —
        // written as two comparisons because `21 <= h < 8` is false for every
        // hour and is the bug this shape avoids.
        return $hour >= self::CLOSES_AT_HOUR || $hour < self::OPENS_AT_HOUR;
    }

    /**
     * The next 08:00 in local time.
     *
     * An instant at 22:00 opens tomorrow morning; one at 03:00 opens this
     * morning. Both are the same eight o'clock to a guest, and getting the day
     * wrong here defers a message by twenty-four hours rather than by five.
     */
    private static function nextOpening(Carbon $local): Carbon
    {
        $opening = $local->copy()->setTime(self::OPENS_AT_HOUR, 0);

        return $opening->greaterThan($local) ? $opening : $opening->addDay();
    }
}
