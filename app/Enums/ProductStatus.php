<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Whether a product is sellable (`docs/data-model.md` §2.3, spec CAT-4).
 *
 * Four states because "not on sale" has three genuinely different meanings, and
 * collapsing them costs the operator information they need:
 *
 * - `draft` — being built, never shown to anyone.
 * - `active` — on sale.
 * - `inactive` — finished and temporarily off sale. Last winter's sunset cruise
 *   in February. Comes back with one click.
 * - `archived` — retired for good, kept because bookings reference it.
 *
 * Soft deletion is a separate axis and means something else again: the operator
 * deleted it. `archived` is a product that ran its course.
 */
enum ProductStatus: string
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    /** Can a guest see and book this right now? */
    public function isSellable(): bool
    {
        return $this === self::Active;
    }

    /** Should it still appear in the operator's own lists? */
    public function isVisibleToOperator(): bool
    {
        return $this !== self::Archived;
    }
}
