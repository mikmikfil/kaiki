<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What kind of trip this is (`docs/data-model.md` §2.3, spec CAT-4).
 *
 * A **presentation** grouping, not a behavioural one: the widget's `list` mount
 * filters by it and the hosted page groups by it, and nothing in the
 * availability or pricing engine reads it. That separation is deliberate —
 * mode drives behaviour, category drives how a guest browses.
 *
 * A fixed list rather than free text so the widget can filter reliably and the
 * WordPress plugin can map a category to a CPT term. `custom` exists so an
 * operator with something genuinely different is not forced into a wrong label.
 */
enum ProductCategory: string
{
    use HasTranslatedLabel;

    case SharedFullDay = 'shared_full_day';
    case SharedHalfDay = 'shared_half_day';
    case PrivateFullDay = 'private_full_day';
    case PrivateHalfDay = 'private_half_day';
    case Sunset = 'sunset';
    case Custom = 'custom';

    /** Does the name suggest a private charter? Presentation only — `mode` decides. */
    public function suggestsPrivate(): bool
    {
        return $this === self::PrivateFullDay || $this === self::PrivateHalfDay;
    }
}
