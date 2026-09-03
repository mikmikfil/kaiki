<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How the widget picks between its light and dark presentation
 * (`docs/data-model.md` §2.2, spec BRD-1).
 *
 * `auto` is the default and means the *visitor's* `prefers-color-scheme`, not
 * the operator's. The widget is embedded on someone else's site, so the two
 * fixed values exist for the operator who knows their host page is one or the
 * other and does not want the booking box disagreeing with it.
 */
enum WidgetTheme: string
{
    use HasTranslatedLabel;

    case Light = 'light';
    case Dark = 'dark';
    case Auto = 'auto';

    /** Does the rendered theme depend on the visitor rather than the operator? */
    public function followsVisitorPreference(): bool
    {
        return $this === self::Auto;
    }
}
