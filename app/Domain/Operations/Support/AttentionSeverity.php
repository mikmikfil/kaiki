<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

/**
 * How loudly one attention row is drawn.
 *
 * Three levels, and they are **only a colour** — the list is ordered by
 * deadline, never by this. A red badge above a boat that sails in three hours
 * is a list an operator has to read in full to use, which is the same as not
 * having a list.
 *
 * So the rule for choosing one is narrow: *what does it cost to notice this
 * tomorrow instead of today?*
 */
enum AttentionSeverity: string
{
    /** A boat sails, or does not, and somebody has to say which. */
    case Critical = 'critical';

    /** Money or a manifest that is late; recoverable, but not on its own. */
    case Warning = 'warning';

    /** Worth knowing before it lapses. Nobody is stranded if it does. */
    case Info = 'info';

    /** The Filament badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::Warning => 'warning',
            self::Info => 'gray',
        };
    }
}
