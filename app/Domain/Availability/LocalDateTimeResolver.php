<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Data\Availability\LocalInstantData;
use App\Models\Tenant;
use App\Support\Tenancy;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * The only place a local date and time becomes a UTC instant
 * (spec AVL-13 to AVL-18, CNV-2, CNV-3, ADR-0016).
 *
 * Every timezone bug this product will ever have traces back to this class, so
 * it is pure, static, and the single public conversion API. AVL-16 says exactly
 * one class may perform this conversion; a second one is how the ticket, the
 * iCal feed and the manifest come to disagree by an hour.
 *
 * ## PHP resolves both DST edges wrongly for our purposes, silently
 *
 * Both traps are real, both are invisible, and both are why this class exists.
 *
 * **Spring forward.** `new DateTimeImmutable('2026-03-29 03:30', Athens)`
 * returns **04:30**. PHP does not complain; it shifts the time an hour forward
 * and hands back a perfectly ordinary object. That is Option B of ADR-0016 —
 * the option that was rejected because it silently moves a departure a guest
 * has booked. So the conversion is checked by rendering the result back and
 * comparing: if the wall clock moved, the time did not exist, and the answer is
 * "no instant" rather than a shifted one.
 *
 * **Autumn fall back.** `new DateTimeImmutable('2026-10-25 03:30', Athens)`
 * returns the **second** occurrence, at +02:00. ADR-0016 fixes the tie-break as
 * the **first** — the one still in summer time — so the resolver subtracts an
 * hour of *absolute* time when it detects the overlap. And absolute is the
 * operative word: `modify('-1 hour')` on a zoned object does **wall-clock**
 * arithmetic and would give 02:30, which is a different local time entirely.
 * The subtraction therefore happens on the Unix timestamp.
 *
 * ## Duration is absolute, always
 *
 * AVL-17: `ends_at_utc = starts_at_utc + duration_minutes` in elapsed minutes.
 * A four-hour cruise that crosses a transition is four hours of sea time and
 * three or five hours of wall clock, and the crew and the vessel schedule care
 * about the first. Adding to a UTC instant gets this right for free, which is
 * the second reason the column is UTC.
 */
final class LocalDateTimeResolver
{
    /**
     * Convert a local date and time in `$timezone` to a UTC instant.
     *
     * @param  Carbon|string  $localDate  a calendar date, never an instant
     * @param  string  $localTime  `HH:MM` or `HH:MM:SS`
     */
    public static function resolve(Carbon|string $localDate, string $localTime, string $timezone): LocalInstantData
    {
        $date = $localDate instanceof Carbon ? $localDate->toDateString() : substr($localDate, 0, 10);
        $time = self::normaliseTime($localTime);
        $zone = new DateTimeZone($timezone);

        $naive = new DateTimeImmutable("{$date} {$time}", $zone);

        // PHP shifts a non-existent local time forward without a word. Render
        // the result back and compare: if the wall clock moved, the time never
        // happened on that date (ADR-0016 Option A).
        if ($naive->format('Y-m-d H:i:s') !== "{$date} {$time}") {
            return LocalInstantData::nonExistent();
        }

        $earlier = (new DateTimeImmutable('@' . ($naive->getTimestamp() - 3600)))->setTimezone($zone);

        // The same wall clock an hour of absolute time earlier means the clock
        // went back and this local time happens twice.
        $ambiguous = $earlier->format('Y-m-d H:i:s') === $naive->format('Y-m-d H:i:s');

        return LocalInstantData::found(
            Carbon::instance($ambiguous ? $earlier : $naive)->utc(),
            $ambiguous,
        );
    }

    /** The same conversion, in a tenant's own timezone. */
    public static function resolveForTenant(Carbon|string $localDate, string $localTime, ?Tenant $tenant = null): LocalInstantData
    {
        return self::resolve($localDate, $localTime, self::timezone($tenant));
    }

    /**
     * The end of a window, in **absolute** elapsed minutes (AVL-17).
     *
     * Public and separate so nothing writes `->addMinutes()` against a zoned
     * value, which is where a trip across a transition would gain or lose an
     * hour of sea time.
     */
    public static function endsAt(Carbon $startsAtUtc, int $durationMinutes): Carbon
    {
        return $startsAtUtc->copy()->utc()->addMinutes(max(0, $durationMinutes));
    }

    /** Render a UTC instant back as its local date, for display only. */
    public static function localDate(Carbon $instantUtc, string $timezone): string
    {
        return $instantUtc->copy()->setTimezone($timezone)->toDateString();
    }

    /** Render a UTC instant back as its local time, for display only. */
    public static function localTime(Carbon $instantUtc, string $timezone): string
    {
        return $instantUtc->copy()->setTimezone($timezone)->format('H:i:s');
    }

    /**
     * The tenant timezone, falling back to the configured default.
     *
     * CNV-2: stored in UTC, displayed in the tenant timezone. `config('app.timezone')`
     * is UTC and would be silently wrong by two or three hours here, so the
     * fallback is the platform default rather than the app's.
     */
    public static function timezone(?Tenant $tenant = null): string
    {
        $tenant ??= Tenancy::current();

        if ($tenant !== null) {
            return $tenant->timezone;
        }

        return (string) config('kaiki.defaults.timezone', 'Europe/Athens');
    }

    /** `HH:MM` and `HH:MM:SS` both arrive from forms and from the database. */
    private static function normaliseTime(string $time): string
    {
        $time = trim($time);

        return strlen($time) === 5 ? "{$time}:00" : $time;
    }
}
