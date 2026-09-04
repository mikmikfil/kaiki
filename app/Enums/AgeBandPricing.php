<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How an age band's price is arrived at (`docs/data-model.md` §2.3, spec CAT-7, PRC-7).
 *
 * Two ways, and operators genuinely use both. A multiplier keeps the ladder
 * intact when the adult price changes — put the adult up by €5 and the child
 * follows — which is what most operators want and why it is the default. A
 * fixed price is for the band that does not scale: an infant at €0, or a
 * senior rate negotiated with a coach company that has nothing to do with the
 * adult fare.
 *
 * The multiplier is in **basis points** for the same reason money is in cents:
 * 5000 is exactly half, and `0.5` on a float is not exactly anything.
 */
enum AgeBandPricing: string
{
    use HasTranslatedLabel;

    /** A proportion of the base band's price, in basis points. */
    case Multiplier = 'multiplier';

    /** Its own price per rate plan, set in `rate_plan_prices` (#21). */
    case Fixed = 'fixed';

    /** Does this mode need `price_multiplier_bp` to be set (CAT-8)? */
    public function requiresMultiplier(): bool
    {
        return $this === self::Multiplier;
    }

    /** Does this mode need a `rate_plan_prices` row before the product can sell? */
    public function requiresExplicitPrice(): bool
    {
        return $this === self::Fixed;
    }
}
