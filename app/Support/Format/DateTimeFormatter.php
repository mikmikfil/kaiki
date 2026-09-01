<?php

declare(strict_types=1);

namespace App\Support\Format;

use App\Support\Tenancy;
use DateTimeInterface;
use IntlDateFormatter;

/**
 * Renders instants per locale, in the tenant's timezone (I18N-6, CNV-2).
 *
 * Everything is stored UTC and displayed in the operator's timezone — so this
 * class resolves the zone from the tenant rather than from `config('app.timezone')`,
 * which is UTC and therefore two or three hours wrong for every Greek operator.
 * Survivable on an API key's expiry; not survivable on a departure time.
 *
 * Greek is `DD/MM/YYYY` and 24-hour, as I18N-6 requires. English is `en_GB` for
 * the reason in {@see Locales} — both locales day-first, so the same departure
 * never reads as two different dates.
 *
 * Patterns are given explicitly rather than taken from `IntlDateFormatter::SHORT`.
 * The short Greek pattern is `3/4/26` — a two-digit year on a booking screen is
 * how somebody reads a 2026 departure as 2025, and CLDR is free to change its
 * short forms between ICU releases.
 */
final class DateTimeFormatter
{
    private const DATE_PATTERN = 'dd/MM/yyyy';

    private const TIME_PATTERN = 'HH:mm';

    public static function date(DateTimeInterface $moment, ?string $locale = null, ?string $timezone = null): string
    {
        return self::render($moment, self::DATE_PATTERN, $locale, $timezone);
    }

    public static function time(DateTimeInterface $moment, ?string $locale = null, ?string $timezone = null): string
    {
        return self::render($moment, self::TIME_PATTERN, $locale, $timezone);
    }

    public static function dateTime(DateTimeInterface $moment, ?string $locale = null, ?string $timezone = null): string
    {
        return self::render(
            $moment,
            self::DATE_PATTERN . ', ' . self::TIME_PATTERN,
            $locale,
            $timezone,
        );
    }

    /**
     * The long form, where the month is spelled out — `3 Απριλίου 2026`.
     *
     * For emails and PDFs, where a guest reads the date once and a misread
     * digit is a missed boat.
     */
    public static function longDate(DateTimeInterface $moment, ?string $locale = null, ?string $timezone = null): string
    {
        return self::render($moment, 'd MMMM yyyy', $locale, $timezone);
    }

    /** The tenant's timezone, or the application default when none is resolved. */
    public static function timezone(): string
    {
        $tenant = Tenancy::current();

        return $tenant === null
            ? (string) config('app.timezone')
            : $tenant->timezone;
    }

    private static function render(
        DateTimeInterface $moment,
        string $pattern,
        ?string $locale,
        ?string $timezone,
    ): string {
        $formatter = new IntlDateFormatter(
            Locales::icu($locale),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timezone ?? self::timezone(),
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );

        return (string) $formatter->format($moment);
    }
}
