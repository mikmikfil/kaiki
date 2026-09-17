<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Port;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * The guest's own trip, as a calendar entry (product owner, 2026-09-18).
 *
 * ## Why an `.ics` file and a Google link, and not one of them
 *
 * They are not alternatives; they are two different populations. A guest
 * reading the confirmation on a phone taps the attachment and iOS or Android
 * offers to add it — no account, no browser, no login. A guest reading it on a
 * desktop with Gmail open wants the entry in the calendar they actually use,
 * and for them a template URL is one click while a downloaded file is a trip
 * through the downloads folder. Shipping only the file loses the second group
 * silently, which is the worst way to lose them: nothing is broken, they simply
 * never do it.
 *
 * Both are built from this one class so the two can never disagree about the
 * time — which is the only fact in them that a guest will act on.
 *
 * ## The entry starts at check-in, not at departure
 *
 * A calendar entry answers "when must I be somewhere", and for a boat that is
 * the check-in, not the moment the lines come off. A guest whose calendar said
 * 10:00 because the boat leaves at 10:00 is a guest standing at the quay at
 * 10:00 watching it go. So `DTSTART` is departure minus the product's
 * `check_in_offset_minutes` where one is set, and the description spells out
 * all three times so nothing is hidden by the shift.
 *
 * `DTEND` is the booking's own `ends_at_utc`. Where that is missing — an
 * imported booking, a preview row that was never saved — the entry lasts two
 * hours rather than being open-ended, because an event with no end is drawn
 * across the whole day in most calendars.
 *
 * ## Everything is UTC, deliberately
 *
 * Not a `VTIMEZONE` block. The correct way to express a local time in
 * iCalendar is a `TZID` plus the timezone's own definition, and clients
 * disagree about what to do when they already hold a different definition for
 * `Europe/Athens`. A UTC instant is unambiguous everywhere and renders in the
 * reader's own zone, which for a guest flying in from another country is the
 * behaviour they want anyway.
 *
 * ## A stable UID, and a `SEQUENCE` that moves
 *
 * The same booking emails a calendar entry at least three times — the
 * confirmation, any change, and the day-before reminder. With a UID derived
 * from the booking's `uuid`, the second and third *replace* the first instead
 * of stacking three copies of the same morning in somebody's calendar. The
 * `SEQUENCE` is `updated_at`'s timestamp: an integer that only ever increases,
 * which is the whole of what the standard asks of it, so a changed trip
 * actually overwrites the old entry rather than being ignored as a duplicate.
 *
 * A cancelled booking is served as `STATUS:CANCELLED` rather than as nothing,
 * so a guest who taps the link from an old email removes the entry rather than
 * re-adding a trip that is not happening.
 */
final class BookingCalendarInvite
{
    /** How long the entry runs when the booking never recorded an end. */
    private const FALLBACK_MINUTES = 120;

    /**
     * When the reminder fires: the day before.
     *
     * The same day as the `pre_departure_24h` email, and that is on purpose —
     * they reach different people. The email is read by whoever opens email;
     * the alarm reaches the guest who books in March, reads nothing until
     * August, and lives out of their calendar.
     */
    private const ALARM = '-P1D';

    private function __construct(
        private readonly Booking $booking,
        private readonly string $locale,
        private readonly ?Tenant $tenant,
    ) {}

    public static function for(Booking $booking, ?string $locale = null): self
    {
        $booking->loadMissing(['product.meetingPoint']);

        $tenant = $booking->tenant_id === null
            ? Tenancy::current()
            : Tenancy::withoutTenancy(static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id));

        return new self($booking, $locale ?? app()->getLocale(), $tenant);
    }

    /**
     * Is there a trip to put in a calendar at all?
     *
     * A booking with no start is a draft or a preview row; an entry for it
     * would land on 1 January 1970. The link is left out rather than offered
     * and broken.
     */
    public function isAvailable(): bool
    {
        return $this->booking->starts_at_utc !== null;
    }

    /**
     * The `.ics` body, built through `sabre/vobject`.
     *
     * By hand this is four rules — CRLF endings, folding at 75 *octets*,
     * escaping `\`, `;`, `,` and newlines inside a value, and never folding
     * mid-character in a Greek meeting point's name — and a writer that gets
     * three of them right produces a file Google accepts and Outlook drops
     * half of. `VesselFeed` already took this decision for the operator's feed.
     */
    public function ics(): string
    {
        $calendar = new VCalendar([
            'PRODID' => '-//Kaiki//Guest booking//EN',
            'VERSION' => '2.0',
            'CALSCALE' => 'GREGORIAN',
            // PUBLISH, never REQUEST. REQUEST makes it a meeting invitation and
            // puts "accept / decline" buttons on a boat trip the guest has
            // already paid for — and sends the operator an RSVP nobody reads.
            'METHOD' => 'PUBLISH',
        ]);

        $event = $calendar->add('VEVENT', [
            'UID' => $this->uid(),
            'DTSTAMP' => Carbon::instance($this->booking->updated_at ?? Carbon::now())->utc()->toDateTime(),
            'DTSTART' => $this->startsAt()->utc()->toDateTime(),
            'DTEND' => $this->endsAt()->utc()->toDateTime(),
            'SEQUENCE' => (int) ($this->booking->updated_at?->getTimestamp() ?? 0),
            'SUMMARY' => $this->summary(),
            'DESCRIPTION' => $this->description(),
            'STATUS' => $this->booking->status === BookingStatus::Cancelled ? 'CANCELLED' : 'CONFIRMED',
            'TRANSP' => 'OPAQUE',
            'URL' => $this->manageUrl(),
        ]);

        $location = $this->location();

        if ($location !== null && $event instanceof VEvent) {
            $event->add('LOCATION', $location);
        }

        $port = $this->port();

        if ($port instanceof Port && $port->lat !== null && $port->lng !== null && $event instanceof VEvent) {
            // Apple Maps and Google Calendar both pin from this, which matters
            // for a quay whose postal address is somebody's guess.
            $event->add('GEO', $port->lat . ';' . $port->lng);
        }

        if ($event instanceof VEvent && $this->booking->status !== BookingStatus::Cancelled) {
            $event->add('VALARM', [
                'ACTION' => 'DISPLAY',
                'TRIGGER' => self::ALARM,
                'DESCRIPTION' => $this->summary(),
            ]);
        }

        return $calendar->serialize();
    }

    /**
     * The one-click link for a guest whose calendar lives in a browser tab.
     *
     * Google's template URL, which is not a documented API and has nonetheless
     * been stable for fifteen years. `ctz` is sent as well as the UTC instants
     * so the confirmation screen Google shows is drawn in the operator's own
     * timezone rather than the reader's, which is the one moment where local
     * time is the reassuring thing to see.
     */
    public function googleUrl(): string
    {
        $dates = $this->startsAt()->utc()->format('Ymd\THis\Z')
            . '/' . $this->endsAt()->utc()->format('Ymd\THis\Z');

        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action' => 'TEMPLATE',
            'text' => $this->summary(),
            'dates' => $dates,
            // Without the manage link, and that is the one difference between
            // the two. `manage_token` is a bearer credential: in the `.ics` it
            // sits in a file on the guest's own device, but in this URL it would
            // be typed into Google's servers, stored on the event, and carried
            // along if the guest ever shares that entry with somebody. The same
            // reasoning as the `no-referrer` on the map link.
            'details' => $this->description(withManageLink: false),
            'location' => $this->location() ?? '',
            'ctz' => $this->timezone(),
        ]);
    }

    /** The route the `.ics` is downloaded from, or null for a booking with no link. */
    public function downloadUrl(): ?string
    {
        $token = (string) $this->booking->manage_token;

        if (trim($token) === '') {
            return null;
        }

        return route('guest.booking.calendar', ['token' => $this->booking->manage_token]);
    }

    /**
     * A filename a phone's downloads folder can live with.
     *
     * The reference, which is the one string the guest recognises when three of
     * these accumulate over a season.
     */
    public function filename(): string
    {
        $reference = str((string) $this->booking->reference)->slug()->value();

        return ($reference === '' ? 'booking' : $reference) . '.ics';
    }

    /**
     * What the guest reads in the calendar's narrow slot.
     *
     * The trip first, the operator second, because a phone truncates and the
     * trip is what identifies the morning. The operator alone where a booking
     * has no product — an imported row, a charter quoted by hand.
     */
    private function summary(): string
    {
        $trip = $this->trip();
        $operator = $this->tenant instanceof Tenant ? $this->tenant->name : (string) config('app.name');

        return $trip === null ? $operator : $trip . ' · ' . $operator;
    }

    /**
     * The body of the entry: the three times, the reference, and the link back.
     *
     * Deliberately short. A calendar description is read in a popover on a
     * phone, so it carries what somebody standing at a quay needs — when to be
     * there, what to say their name is booked under, and one link to
     * everything else — rather than a copy of the email.
     */
    private function description(bool $withManageLink = true): string
    {
        $lines = [];
        $checkIn = $this->checkInAt();
        $starts = $this->localDeparture();

        if ($checkIn !== null) {
            $lines[] = __('mail.common.check_in', [], $this->locale) . ': ' . $checkIn->format('H:i');
        }

        $lines[] = __('mail.common.departure', [], $this->locale) . ': ' . $starts->format('H:i');

        if ($this->booking->ends_at_utc !== null) {
            $lines[] = __('mail.common.return', [], $this->locale) . ': '
                . Carbon::instance($this->booking->ends_at_utc)->setTimezone($this->timezone())->format('H:i');
        }

        $lines[] = __('mail.common.reference', [], $this->locale) . ': ' . $this->booking->reference;

        $port = $this->port();

        if ($port instanceof Port) {
            $map = $port->mapsUrl();

            if ($map !== null) {
                $lines[] = __('mail.common.open_map', [], $this->locale) . ': ' . $map;
            }
        }

        $manage = $withManageLink ? $this->manageUrl() : null;

        if ($manage !== null) {
            $lines[] = __('mail.common.manage_booking', [], $this->locale) . ': ' . $manage;
        }

        return implode("\n", $lines);
    }

    /** The meeting point as one line, which is all a calendar's location field is. */
    private function location(): ?string
    {
        $port = $this->port();

        if (! $port instanceof Port) {
            return null;
        }

        $name = trim((string) $port->getTranslation('name', $this->locale, true));
        $address = trim((string) $port->address);

        return trim(implode(', ', array_filter([$name, $address]))) ?: null;
    }

    /** `DTSTART`: check-in where the product defines one, departure otherwise. */
    private function startsAt(): Carbon
    {
        return $this->checkInAt() ?? $this->localDeparture();
    }

    private function endsAt(): Carbon
    {
        $ends = $this->booking->ends_at_utc;

        if ($ends !== null && $ends->greaterThan($this->booking->starts_at_utc)) {
            return Carbon::instance($ends);
        }

        return $this->localDeparture()->copy()->addMinutes(self::FALLBACK_MINUTES);
    }

    private function checkInAt(): ?Carbon
    {
        $product = $this->booking->product;
        $offset = $product instanceof Product ? (int) $product->check_in_offset_minutes : 0;

        return $offset > 0 ? $this->localDeparture()->copy()->subMinutes($offset) : null;
    }

    private function localDeparture(): Carbon
    {
        return Carbon::instance($this->booking->starts_at_utc)->setTimezone($this->timezone());
    }

    private function timezone(): string
    {
        $zone = $this->tenant?->timezone;

        return $zone === null || $zone === '' ? (string) config('app.timezone') : $zone;
    }

    private function trip(): ?string
    {
        $product = $this->booking->product;

        if (! $product instanceof Product) {
            return null;
        }

        return trim((string) $product->getTranslation('title', $this->locale, true)) ?: null;
    }

    private function port(): ?Port
    {
        return $this->booking->product?->meetingPoint;
    }

    private function manageUrl(): ?string
    {
        return $this->downloadUrl() === null
            ? null
            : route('guest.booking', ['token' => $this->booking->manage_token]);
    }

    /**
     * The identity of this trip in the guest's calendar, for ever.
     *
     * The booking's `uuid` rather than its reference: a reference is unique per
     * operator and two operators on this platform can both issue `KAI-1042`,
     * which in a guest's calendar would be one entry overwriting the other.
     */
    private function uid(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'kaiki';

        return 'booking-' . $this->booking->uuid . '@' . $host;
    }
}
