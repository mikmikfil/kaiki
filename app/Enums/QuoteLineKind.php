<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a line on a quote is (`docs/data-model.md` §2.5, spec BKG-27).
 *
 * ## The sign lives here, not in the amount
 *
 * §1.4, and §2.5 restates it for this table: *"positive even for `discount` —
 * the `kind` carries the sign."* A negative `unit_price_cents` would make every
 * `SUM` in the system a question about which rows were included, and the column
 * is `unsignedInteger` so the wrong version fails at the database rather than
 * in a total nobody checked.
 *
 * {@see self::signum()} is the one place that mapping is written down.
 */
enum QuoteLineKind: string
{
    use HasTranslatedLabel;

    /** The boat itself — the line every quote has. */
    case Charter = 'charter';

    case Extra = 'extra';

    /** Fuel, mooring, a cleaning charge. Added, like the others. */
    case Fee = 'fee';

    case Discount = 'discount';

    /** +1 for everything that adds, −1 for the one thing that takes away. */
    public function signum(): int
    {
        return $this === self::Discount ? -1 : 1;
    }
}
