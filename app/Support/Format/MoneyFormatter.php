<?php

declare(strict_types=1);

namespace App\Support\Format;

use Brick\Money\Money;

/**
 * Renders integer cents as money, per locale (I18N-8, CNV-1, PRC-16).
 *
 * **Cents in, string out — no float appears anywhere in this class.**
 * `Money::ofMinor()` takes the minor unit directly, so there is no division by
 * 100 to get wrong. A formatter that accepted euros would invite a caller to
 * divide first and lose a cent on a rounding boundary, and that surfaces months
 * later as an invoice that disagrees with the booking by one cent.
 *
 * Currency is always explicit (PRC-16). EUR is a default argument rather than
 * an assumption baked into the arithmetic, so EXT-6's second currency stays a
 * data and UI change and never a change to how sums are done.
 *
 * The output contains a **non-breaking space** before or after the symbol,
 * because that is what CLDR specifies and it stops `1.234,50` wrapping away
 * from its `€`. Tests normalise whitespace before asserting rather than pinning
 * the exact byte, which would turn an ICU upgrade into a red build.
 */
final class MoneyFormatter
{
    public const DEFAULT_CURRENCY = 'EUR';

    public static function format(
        int $cents,
        ?string $locale = null,
        string $currency = self::DEFAULT_CURRENCY,
    ): string {
        return Money::ofMinor($cents, $currency)->formatTo(Locales::icu($locale));
    }
}
