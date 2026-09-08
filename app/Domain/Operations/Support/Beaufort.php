<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

/**
 * Metres per second to the scale a skipper actually uses (ADR-0027).
 *
 * ## Why this is a class with a test rather than a division
 *
 * The Beaufort scale is not linear and the tempting approximations are wrong in
 * the middle, which is exactly where an operator's threshold sits. A boat that
 * stops sailing at 6 Bft cares enormously about the difference between 10.7 and
 * 10.8 m/s, and nowhere else on the scale.
 *
 * Every comparison this feature makes — *is Thursday over this vessel's limit* —
 * runs through here, so a wrongly derived number is a cancellation that should
 * not have happened, or a sailing that should not have gone.
 *
 * The boundaries are the WMO ones, upper-inclusive: force 4 is 5.5 to 7.9 m/s,
 * and 8.0 is already force 5.
 */
final class Beaufort
{
    /**
     * The upper bound of each force, in metres per second.
     *
     * Index is the force; the value is the highest speed still in it. Force 12
     * has no upper bound and is therefore absent — anything above 32.6 is 12,
     * and a table that pretended otherwise would cap a hurricane at 11.
     *
     * @var list<float>
     */
    private const UPPER_BOUNDS = [
        0.5,   // 0 — calm
        1.5,   // 1
        3.3,   // 2
        5.4,   // 3
        7.9,   // 4
        10.7,  // 5
        13.8,  // 6 — where most small-boat operators stop
        17.1,  // 7
        20.7,  // 8
        24.4,  // 9
        28.4,  // 10
        32.6,  // 11
    ];

    /** The highest force on the scale. */
    public const MAX = 12;

    /**
     * The force for a speed in metres per second.
     *
     * A negative speed is not a physical reading; it is a provider returning
     * something unexpected, and force 0 is the answer that cannot cause a
     * cancellation on its own.
     */
    public static function fromMetresPerSecond(float $speed): int
    {
        if ($speed < 0) {
            return 0;
        }

        foreach (self::UPPER_BOUNDS as $force => $upper) {
            if ($speed <= $upper) {
                return $force;
            }
        }

        return self::MAX;
    }

    /**
     * The force for a speed in kilometres per hour.
     *
     * Present because a provider's default unit is a setting somebody can
     * change in a URL, and a conversion done at the call site is one that gets
     * forgotten the day the URL changes.
     */
    public static function fromKilometresPerHour(float $speed): int
    {
        return self::fromMetresPerSecond($speed / 3.6);
    }
}
