<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How loudly a platform announcement is shown (spec SAA-1).
 *
 * Two, deliberately. "Information" is the maintenance window next Sunday;
 * "warning" is something an operator should act on today. A third level would
 * be a colour nobody learns the meaning of.
 */
enum AnnouncementSeverity: string
{
    use HasTranslatedLabel;

    case Info = 'info';

    case Warning = 'warning';

    /**
     * Its labels live with the rest of the platform panel's strings, in
     * `lang/{locale}/platform.php`, rather than in the shared `enums.php`.
     */
    public static function translationNamespace(): string
    {
        return 'platform.severity';
    }
}
