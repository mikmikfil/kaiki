<?php

declare(strict_types=1);

use App\Support\Format\DateTimeFormatter;
use App\Support\Format\MoneyFormatter;

/*
 * Spec I18N-6 and I18N-8: dates, times, numbers and money are formatted per
 * locale; Greek uses 24-hour time and DD/MM/YYYY; currency is EUR only.
 *
 * ---
 *
 * **Whitespace is normalised before every assertion.** CLDR puts a narrow
 * no-break space (U+202F) or a no-break space (U+00A0) beside a currency
 * symbol, and which one it is has changed between ICU releases. Pinning the
 * exact byte would make this file go red on a CI image upgrade while the
 * application is perfectly correct — the classic way a formatting test becomes
 * something people delete rather than trust.
 */

function formattedSpaces(string $value): string
{
    return trim((string) preg_replace('/\p{Zs}+/u', ' ', $value));
}

/** 2026-04-03 14:05 UTC — a spring afternoon, and 17:05 in Athens. */
function departureMoment(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-04-03 14:05:00', new DateTimeZone('UTC'));
}

it('formats money from integer cents, per locale', function (): void {
    expect(formattedSpaces(MoneyFormatter::format(123_450, 'el')))->toBe('1.234,50 €')
        ->and(formattedSpaces(MoneyFormatter::format(123_450, 'en')))->toBe('€1,234.50');
})->group('fast', 'i18n');

it('uses Greek decimal and thousands separators', function (): void {
    // The separators are swapped relative to English, which is the single most
    // common way a Greek price renders as a thousand times itself.
    expect(formattedSpaces(MoneyFormatter::format(123_450, 'el')))
        ->toContain('1.234')   // full stop groups thousands
        ->toContain(',50');    // comma introduces the decimals
})->group('fast', 'i18n');

it('never loses a cent', function (): void {
    expect(formattedSpaces(MoneyFormatter::format(1, 'el')))->toBe('0,01 €')
        ->and(formattedSpaces(MoneyFormatter::format(99, 'en')))->toBe('€0.99')
        ->and(formattedSpaces(MoneyFormatter::format(100, 'el')))->toBe('1,00 €');
})->group('fast', 'i18n');

it('formats a negative amount, for refunds', function (): void {
    expect(formattedSpaces(MoneyFormatter::format(-4_550, 'el')))->toContain('45,50')
        ->and(formattedSpaces(MoneyFormatter::format(-4_550, 'el')))->toContain('-');
})->group('fast', 'i18n');

it('formats dates as DD/MM/YYYY in both locales', function (): void {
    // I18N-6 pins Greek. English is en_GB on purpose: a departure that reads
    // 03/04/2026 in Greek must not read 04/03/2026 in English, because that is
    // a guest on the wrong boat rather than a cosmetic difference.
    expect(DateTimeFormatter::date(departureMoment(), 'el', 'Europe/Athens'))->toBe('03/04/2026')
        ->and(DateTimeFormatter::date(departureMoment(), 'en', 'Europe/Athens'))->toBe('03/04/2026');
})->group('fast', 'i18n');

it('formats times in 24 hours, with no am or pm', function (): void {
    $greek = DateTimeFormatter::time(departureMoment(), 'el', 'Europe/Athens');
    $english = DateTimeFormatter::time(departureMoment(), 'en', 'Europe/Athens');

    expect($greek)->toBe('17:05')
        ->and($english)->toBe('17:05')
        // ICU renders Greek afternoons as `μ.μ.`; an operator scanning a
        // manifest of departure times must not have to parse that.
        ->and($greek)->not->toContain('μ.μ.')
        ->and($english)->not->toContain('PM');
})->group('fast', 'i18n');

it('renders in the given timezone, not in UTC', function (): void {
    // CNV-2: stored UTC, displayed in the operator's timezone. 14:05 UTC is
    // 17:05 in Athens in April, and a departure board three hours out is the
    // most expensive formatting bug in this application.
    expect(DateTimeFormatter::time(departureMoment(), 'el', 'Europe/Athens'))->toBe('17:05')
        ->and(DateTimeFormatter::time(departureMoment(), 'el', 'UTC'))->toBe('14:05');
})->group('fast', 'i18n');

it('crosses midnight into the previous day where the timezone says so', function (): void {
    // 00:30 UTC on the 4th is 03:30 on the 4th in Athens, but 20:30 on the 3rd
    // in New York. The date and the time have to move together or a booking
    // lands on the wrong day.
    $midnight = new DateTimeImmutable('2026-04-04 00:30:00', new DateTimeZone('UTC'));

    expect(DateTimeFormatter::date($midnight, 'el', 'America/New_York'))->toBe('03/04/2026')
        ->and(DateTimeFormatter::time($midnight, 'el', 'America/New_York'))->toBe('20:30');
})->group('fast', 'i18n');

it('spells the month out in Greek for the long form', function (): void {
    expect(DateTimeFormatter::longDate(departureMoment(), 'el', 'Europe/Athens'))->toContain('Απριλίου')
        ->and(DateTimeFormatter::longDate(departureMoment(), 'en', 'Europe/Athens'))->toContain('April');
})->group('fast', 'i18n');

it('combines date and time in the same order in both locales', function (): void {
    expect(DateTimeFormatter::dateTime(departureMoment(), 'el', 'Europe/Athens'))->toBe('03/04/2026, 17:05')
        ->and(DateTimeFormatter::dateTime(departureMoment(), 'en', 'Europe/Athens'))->toBe('03/04/2026, 17:05');
})->group('fast', 'i18n');

it('falls back to the current application locale', function (): void {
    app()->setLocale('el');
    expect(formattedSpaces(MoneyFormatter::format(500)))->toBe('5,00 €');

    app()->setLocale('en');
    expect(formattedSpaces(MoneyFormatter::format(500)))->toBe('€5.00');
})->group('fast', 'i18n');

it('falls back to English formatting for a locale it does not ship', function (): void {
    // Not an exception: a stray locale must render a readable price rather than
    // take down the page that shows it.
    expect(formattedSpaces(MoneyFormatter::format(500, 'fr')))->toBe('€5.00');
})->group('fast', 'i18n');
