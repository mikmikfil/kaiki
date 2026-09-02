<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Whether a vessel is available to carry passengers (`docs/data-model.md` §2.3).
 *
 * `maintenance` is separate from `inactive` because they mean different things
 * to the person reading the fleet list: `inactive` is a boat the operator has
 * put away, `maintenance` is one they expect back. Both are unsellable, and
 * {@see self::isSellable()} is the only place that equivalence is written down
 * — a caller comparing against `Active` by hand would keep working right up
 * until a fourth case existed.
 */
enum VesselStatus: string
{
    use HasTranslatedLabel;

    case Active = 'active';
    case Inactive = 'inactive';
    case Maintenance = 'maintenance';

    /** May new departures be generated and sold on this boat? */
    public function isSellable(): bool
    {
        return $this === self::Active;
    }
}
