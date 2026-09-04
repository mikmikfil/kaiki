<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where the brand's font comes from (`docs/data-model.md` §2.2, spec BRD-1).
 *
 * This is not a cosmetic distinction. It decides **whether the widget loads a
 * Google Fonts URL at all** — a third-party request from a page the operator
 * does not own, which is a GDPR question (`fonts.gstatic.com` sees the visitor's
 * IP) before it is a design one. `system` guarantees no such request exists,
 * which is why it is the default and why the column is an enum rather than a
 * nullable `google_font_name`.
 */
enum FontSource: string
{
    use HasTranslatedLabel;

    /** A stack already on the device. No network request, ever. */
    case System = 'system';

    /** A Google Fonts family, fetched by the widget and the hosted page. */
    case Google = 'google';

    /** Does choosing this source mean a third-party font request? */
    public function requiresExternalRequest(): bool
    {
        return $this === self::Google;
    }
}
