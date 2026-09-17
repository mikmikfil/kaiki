<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How a discount code takes money off (product owner, 2026-09-17).
 *
 * A percentage of the trip and its extras, or a fixed amount in euros that
 * never takes the total below zero.
 */
enum DiscountKind: string
{
    use HasTranslatedLabel;

    case Percent = 'percent';
    case Fixed = 'fixed';
}
