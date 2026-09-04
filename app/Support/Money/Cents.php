<?php

declare(strict_types=1);

namespace App\Support\Money;

use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * The one place `brick/money` is constructed, always in EUR (CNV-1, CNV-4).
 *
 * ## Why a wrapper around a library that is already correct
 *
 * Not to hide it. To make the two decisions that must never vary — **EUR** and
 * **half up** — impossible to make differently in the next file. CNV-4 fixes
 * the rounding mode for the whole money path, and a rounding mode passed at
 * each call site is a rounding mode that eventually differs by one call site
 * and by one cent, on an invoice, months later.
 *
 * Every method takes and returns integer **cents**. Nothing here accepts or
 * produces a float, which is CNV-1 stated as a type signature rather than as a
 * convention.
 */
final class Cents
{
    public const CURRENCY = 'EUR';

    /** The mode recorded in every price snapshot (§3.4 `rounding`). */
    public const ROUNDING = 'HALF_UP';

    /**
     * `$cents * $numerator / $denominator`, rounded half up.
     *
     * The shape every derived amount in the engine has: a multiplier in basis
     * points, a percentage, a VAT share. Kept as one function so the rounding
     * happens in one place and can be reasoned about once.
     */
    public static function scale(int $cents, int $numerator, int $denominator): int
    {
        if ($cents === 0 || $numerator === 0) {
            return 0;
        }

        return Money::ofMinor($cents, self::CURRENCY)
            ->multipliedBy($numerator, RoundingMode::UNNECESSARY)
            ->dividedBy($denominator, RoundingMode::HALF_UP)
            ->getMinorAmount()
            ->toInt();
    }

    /** Basis points, the unit `price_multiplier_bp` is stored in: 10000 = 100%. */
    public static function applyBasisPoints(int $cents, int $basisPoints): int
    {
        return self::scale($cents, $basisPoints, 10_000);
    }

    /** Whole percent, the unit `deposit_percent` is stored in. */
    public static function applyPercent(int $cents, int $percent): int
    {
        return self::scale($cents, $percent, 100);
    }

    /**
     * The net half of a VAT-inclusive amount (§3.4).
     *
     * Greek passenger transport prices are quoted inclusive, so the gross is
     * the known figure and the split is derived from it. Net is rounded and
     * **VAT is the remainder**, never rounded separately — two independently
     * rounded halves can fail to add up to the total they came from, and an
     * invoice whose lines do not sum is a myDATA rejection.
     */
    public static function netOfInclusive(int $grossCents, int $rateBasisPoints): int
    {
        if ($grossCents === 0 || $rateBasisPoints === 0) {
            return $grossCents;
        }

        return self::scale($grossCents, 10_000, 10_000 + $rateBasisPoints);
    }

    /** The VAT half, as the remainder. See {@see self::netOfInclusive()}. */
    public static function vatOfInclusive(int $grossCents, int $rateBasisPoints): int
    {
        return $grossCents - self::netOfInclusive($grossCents, $rateBasisPoints);
    }
}
