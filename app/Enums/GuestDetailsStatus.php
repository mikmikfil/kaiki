<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Whether this booking still owes us passenger details (`docs/data-model.md` §2.5).
 *
 * `not_required` is the default and the common case: most day trips need a lead
 * name and nothing more. It is a distinct value rather than a null so the
 * reminder scheduler's query is an equality check and never has to reason about
 * missing data.
 */
enum GuestDetailsStatus: string
{
    use HasTranslatedLabel;

    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Complete = 'complete';

    /** Should the −48h and −24h reminders consider this booking? */
    public function needsReminder(): bool
    {
        return $this === self::Pending;
    }
}
