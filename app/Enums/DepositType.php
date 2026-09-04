<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How much a guest pays up front (`docs/data-model.md` §2.3, spec CAT-10, PRC-23).
 *
 * Three shapes because operators genuinely use all three: nothing up front for
 * a cheap shared seat, a percentage for a charter where the risk scales with
 * the price, and a flat figure for the operator who wants €200 whatever the
 * boat costs.
 *
 * The enum exists so the *other two columns* have a meaning. `deposit_percent`
 * and `deposit_fixed_cents` are both nullable, and which of them is required is
 * this enum's answer — a rule that would otherwise be spread across a form, an
 * importer and an API validator.
 */
enum DepositType: string
{
    use HasTranslatedLabel;

    /** Pay in full at checkout. */
    case None = 'none';

    /** A share of the total, in whole percent. */
    case Percent = 'percent';

    /** A flat figure in cents, whatever the total. */
    case Fixed = 'fixed';

    /** Which column this type requires, or null when it requires neither. */
    public function requiredField(): ?string
    {
        return match ($this) {
            self::None => null,
            self::Percent => 'deposit_percent',
            self::Fixed => 'deposit_fixed_cents',
        };
    }

    /** Does a guest pay less than the full price up front? */
    public function isPartial(): bool
    {
        return $this !== self::None;
    }
}
