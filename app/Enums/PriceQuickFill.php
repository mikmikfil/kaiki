<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Support\Money\Cents;

/**
 * The shortcut buttons on a trip's price table: «Ίδια», «Μισή τιμή», «−30%»,
 * «Δωρεάν» (product owner, 2026-09-17).
 *
 * **A way to type euros, never a way to store a percentage.** Pressing «Μισή
 * τιμή» on the child's row writes 22,50 € into each period's cell, worked out
 * from that period's adult price, and what is saved is the 22,50. Nothing
 * remembers that it was half: the operator asked to stop thinking in «5000»,
 * and a rule kept behind a euro figure is the same thing wearing a disguise.
 *
 * Rounded half up to the cent through {@see Cents}, the one rounding the whole
 * money path uses, so a child fare filled here is exactly the fare the engine
 * would have derived from the old multiplier.
 */
enum PriceQuickFill: string
{
    use HasTranslatedLabel;

    case Same = 'same';
    case Half = 'half';
    case LessThirty = 'less_thirty';
    case Free = 'free';

    /** The share of the base price, in basis points (10000 = all of it). */
    public function basisPoints(): int
    {
        return match ($this) {
            self::Same => 10_000,
            self::Half => 5_000,
            self::LessThirty => 7_000,
            self::Free => 0,
        };
    }

    /** The euro amount this button writes, from the base band's price in cents. */
    public function apply(int $baseCents): int
    {
        return Cents::applyBasisPoints($baseCents, $this->basisPoints());
    }
}
