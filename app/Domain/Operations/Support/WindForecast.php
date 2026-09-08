<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

/**
 * One day's wind, as a skipper reads it (ADR-0027).
 *
 * ## The gust is carried separately, and it is the one that decides
 *
 * A mean of 5 Bft with gusts to 8 is not a 5 Bft day to anybody standing on a
 * flybridge. Providers report both; a value object that averaged them, or kept
 * only the mean, would flatten exactly the days this feature exists to catch.
 *
 * {@see self::force()} therefore answers with the **higher** of the two, and
 * the panel shows which one it came from — an operator who is told "7" wants to
 * know whether that is the wind or a gust before they cancel a charter.
 */
final class WindForecast
{
    public function __construct(
        /** The local date, `Y-m-d`, in the operator's timezone. */
        public readonly string $date,
        /** Daily maximum sustained wind. */
        public readonly int $meanForce,
        /** Daily maximum gust. Null when the provider does not report one. */
        public readonly ?int $gustForce = null,
    ) {}

    /**
     * The number a decision is made against.
     *
     * The higher of mean and gust. Erring toward the larger figure is the safe
     * direction here and the asymmetry is the reason: a day flagged that turns
     * out fine costs an operator a second look at a screen, and a day *not*
     * flagged that turns out rough costs them a boat full of people in weather
     * they chose to sail into.
     */
    public function force(): int
    {
        return max($this->meanForce, $this->gustForce ?? 0);
    }

    /** Is the deciding number the gust rather than the sustained wind? */
    public function drivenByGust(): bool
    {
        return $this->gustForce !== null && $this->gustForce > $this->meanForce;
    }

    /** Does this day exceed a vessel's limit? */
    public function exceeds(?int $maxForce): bool
    {
        return $maxForce !== null && $this->force() > $maxForce;
    }
}
