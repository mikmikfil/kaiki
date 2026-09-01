<?php

declare(strict_types=1);

namespace App\Support\Format;

/**
 * Maps an application locale onto the ICU locale the formatters use.
 *
 * **English here means `en_GB`, not `en_US`** — so both locales are day-first
 * and 24-hour (I18N-6). The spec pins Greek formatting and says nothing about
 * English, and the tempting default is wrong: a Greek operator who switches the
 * panel to English must not see `04/03/2026` for the departure that read
 * `03/04/2026` a moment ago. That ambiguity is a guest put on the wrong boat,
 * not a cosmetic difference, and it is invisible for eleven days of every month.
 *
 * The same reasoning covers money and numbers, where `en_GB` and `en_US` agree
 * anyway — one mapping, one place to change.
 */
final class Locales
{
    private const ICU = [
        'el' => 'el_GR',
        'en' => 'en_GB',
    ];

    public static function icu(?string $locale = null): string
    {
        $locale ??= (string) app()->getLocale();

        return self::ICU[$locale] ?? self::ICU['en'];
    }
}
