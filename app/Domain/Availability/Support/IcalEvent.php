<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\Actions\SyncIcalSource;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;
use Throwable;

/**
 * One occupation parsed out of somebody else's calendar (spec OPS-13, OPS-15).
 *
 * ## The feeds this has to survive were not written for us
 *
 * Airbnb, Booking.com, Google and a hundred property-management systems each
 * emit iCalendar their own way, and the differences are not exotic — they are
 * the ordinary shape of the format:
 *
 * - **`DTSTART;VALUE=DATE`** for an all-day booking, with **`DTEND` exclusive**.
 *   Airbnb's `20260704`–`20260706` is two nights, not three days, and reading it
 *   as inclusive blocks a boat on a day it is free. This is the single most
 *   common iCal integration bug in the accommodation trade.
 * - **A floating `DTSTART` with no timezone**, which means *the reader's* local
 *   time — so it must be interpreted in the operator's timezone, not in UTC.
 * - **A missing `DTEND`**, where the specification says a date-valued start
 *   lasts one day and a time-valued start lasts zero. Zero-length is useless as
 *   an occupation, so it is skipped rather than stored as a block that can
 *   never conflict with anything.
 * - **`UID` values that repeat across recurrences**, and feeds with no `UID` at
 *   all. OPS-15's idempotency is *by* UID, so a missing one has to be replaced
 *   with something stable — see {@see self::uid()}.
 *
 * ## Cancelled events are events too
 *
 * `STATUS:CANCELLED` means the source is telling us the booking is off. Storing
 * it as a block would keep a boat unavailable for a charter that no longer
 * exists — so it is parsed and marked, and {@see SyncIcalSource}
 * treats it exactly like an event that disappeared.
 */
final class IcalEvent
{
    private function __construct(
        public readonly string $uid,
        public readonly Carbon $startUtc,
        public readonly Carbon $endUtc,
        public readonly ?string $summary,
        public readonly bool $isAllDay,
        public readonly bool $isCancelled,
    ) {}

    /**
     * Parse a feed body into events.
     *
     * A malformed feed raises rather than returning an empty list. The two
     * outcomes are not the same and must not look the same: an empty calendar
     * means *"the boat is free"* and would delete every block the source ever
     * created, which is a boat sold twice. A parse failure means *"we do not
     * know"*, and not knowing must leave yesterday's answer alone.
     *
     * @return list<self>
     *
     * @throws ParseException
     */
    public static function parse(string $body, string $timezone): array
    {
        $calendar = Reader::read($body, Reader::OPTION_FORGIVING);

        if (! $calendar instanceof VCalendar) {
            throw new ParseException('Not a VCALENDAR.');
        }

        $events = [];

        foreach ($calendar->select('VEVENT') as $component) {
            if (! $component instanceof VEvent) {
                continue;
            }

            $event = self::fromComponent($component, $timezone);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private static function fromComponent(VEvent $component, string $timezone): ?self
    {
        $start = self::instant($component, 'DTSTART', $timezone);

        if ($start === null) {
            return null;
        }

        $allDay = self::isDateOnly($component, 'DTSTART');
        $end = self::instant($component, 'DTEND', $timezone);

        if ($end === null) {
            // RFC 5545 §3.6.1: a date-valued start with no end lasts one day.
            // A time-valued one lasts zero, which cannot occupy a boat.
            if (! $allDay) {
                return null;
            }

            $end = $start->copy()->addDay();
        }

        if ($end->lessThanOrEqualTo($start)) {
            return null;
        }

        return new self(
            uid: self::uid($component, $start, $end),
            startUtc: $start->copy()->utc(),
            endUtc: $end->copy()->utc(),
            summary: self::text($component, 'SUMMARY'),
            isAllDay: $allDay,
            isCancelled: strtoupper((string) self::text($component, 'STATUS')) === 'CANCELLED',
        );
    }

    /**
     * The event's identity for OPS-15's idempotency.
     *
     * A real `UID` when there is one. When there is not — and there are feeds
     * in this trade that omit it — the fallback is a hash of the window, which
     * is stable across polls for as long as the event does not move. It is not
     * as good: an event whose times are edited at the source arrives as a new
     * identity and the old one is removed, so the block is replaced rather than
     * updated. That is the correct outcome by luck rather than by design, and
     * it beats the alternative of every poll creating duplicates for ever.
     *
     * `RECURRENCE-ID` is folded in, because a recurring event repeats its `UID`
     * for every instance — without it, a weekly cleaning slot would collapse to
     * one block and the unique index would reject the rest as duplicates.
     */
    private static function uid(VEvent $component, Carbon $start, Carbon $end): string
    {
        $uid = self::text($component, 'UID');

        if ($uid === null || trim($uid) === '') {
            return 'kaiki-derived-' . substr(hash('sha256', $start->toIso8601String() . '|' . $end->toIso8601String()), 0, 32);
        }

        $recurrence = self::text($component, 'RECURRENCE-ID');

        $uid = trim($uid) . ($recurrence === null ? '' : '#' . trim($recurrence));

        // The column is 190 characters and some systems emit far longer UIDs.
        // Truncating would make two long UIDs sharing a prefix collide, so an
        // over-long one is hashed instead — stable, and still unique.
        return strlen($uid) <= 190 ? $uid : 'sha256:' . hash('sha256', $uid);
    }

    /**
     * Read a date-time property as an instant.
     *
     * The timezone argument is the operator's, and it is used only for a
     * **floating** value — one with neither a `TZID` nor a trailing `Z`. RFC
     * 5545 says such a value means local time wherever it is read, and for a
     * boat in Aegina that is Athens, not UTC.
     */
    private static function instant(VEvent $component, string $property, string $timezone): ?Carbon
    {
        $value = $component->{$property} ?? null;

        if ($value === null) {
            return null;
        }

        try {
            $dateTime = $value->getDateTime(new DateTimeZone($timezone));
        } catch (Throwable) {
            return null;
        }

        return Carbon::instance($dateTime);
    }

    /** Is this property a bare date — `VALUE=DATE` — rather than a date-time? */
    private static function isDateOnly(VEvent $component, string $property): bool
    {
        $value = $component->{$property} ?? null;

        if ($value === null) {
            return false;
        }

        $parameter = $value['VALUE'] ?? null;

        return $parameter !== null && strtoupper((string) $parameter) === 'DATE';
    }

    private static function text(VEvent $component, string $property): ?string
    {
        $value = $component->{$property} ?? null;

        if ($value === null) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }
}
