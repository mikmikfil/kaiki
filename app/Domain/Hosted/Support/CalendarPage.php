<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Filament\App\Pages\SearchSettings;
use App\Models\Tenant;

/**
 * Whether «Ημερολόγιο» is in an operator's site menu (2026-09-25).
 *
 * On unless they switch it off, on «Η σελίδα αναζήτησής σας»
 * ({@see SearchSettings}) beside the search filters —
 * the same screen, because it is the same kind of choice: what a visitor can
 * browse by. Stored in `tenants.settings` under `calendar.in_menu`, read on
 * every render, so the switch takes effect on the next page view.
 *
 * Only the menu entries go. The page itself still answers, so a link an
 * operator already sent a guest keeps working.
 */
final class CalendarPage
{
    public const SETTINGS_KEY = 'calendar';

    public static function inMenu(Tenant $tenant): bool
    {
        $stored = $tenant->settings[self::SETTINGS_KEY]['in_menu'] ?? true;

        return filter_var($stored, FILTER_VALIDATE_BOOL);
    }
}
