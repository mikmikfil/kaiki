<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Why a voucher was issued (`docs/data-model.md` §2.5).
 *
 * Kept because "we gave a guest €200" is a question an accountant asks in
 * aggregate, and a free-text note cannot be summed. Weather cancellations in
 * particular are a category an operator wants to see a season's total for.
 */
enum VoucherReason: string
{
    use HasTranslatedLabel;

    case WeatherCancellation = 'weather_cancellation';
    case OperatorCancellation = 'operator_cancellation';
    case ForceMajeure = 'force_majeure';
    case Goodwill = 'goodwill';
    case Manual = 'manual';
}
