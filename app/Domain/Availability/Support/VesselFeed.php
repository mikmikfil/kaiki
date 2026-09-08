<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\IcalFeed;
use App\Models\VesselBlock;
use Carbon\Carbon as CarbonBase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Sabre\VObject\Component\VCalendar;

/**
 * One vessel's busy periods, as an iCalendar feed (spec OPS-13, OPS-14).
 *
 * ## OPS-14 is the whole design, and it is a subtraction
 *
 * > *"iCal export feeds are tokenised, unguessable, revocable and rate-limited.
 * > They expose no guest personal data, only busy periods with a neutral
 * > summary."*
 *
 * The URL is unauthenticated by necessity — Google Calendar and Airbnb will not
 * send a header — so everything this file contains is, in practice, public to
 * anybody who ever sees the link. It is pasted into third-party services, sits
 * in their logs, and is forwarded between colleagues. **So the feed says only
 * that the boat is busy.**
 *
 * No guest name, no reference, no email, no party size, no price, and no
 * `ATTENDEE` property — the last of which is the one an implementer adds
 * without thinking, because that is what an attendee field is *for*.
 *
 * The trip's own title is absent too, and that is the less obvious call: a
 * product name is not personal data, but «Ηλιοβασίλεμα, 4 άτομα» in somebody
 * else's calendar tells a competitor the operator's entire schedule and load
 * factor. A neutral summary costs the operator nothing, because they read their
 * own calendar in the panel.
 *
 * ## `include_guest_names` exists in the schema and is deliberately not read
 *
 * §2.7 gave `ical_feeds` that column, defaulting to false, on the reasoning
 * that an operator could opt in. OPS-14 does not offer that choice — *"expose
 * no guest personal data"* has no exception clause — and the spec is the
 * contract (`CLAUDE.md`). So nothing in this class consults the flag, and the
 * contradiction is recorded in `docs/BUILD-LOG.md` rather than resolved by
 * whichever document was read last.
 *
 * ## Stable UIDs, because a feed is re-read for ever
 *
 * A subscriber fetches this every few hours and diffs it. A UID that changed
 * between fetches would delete and recreate every event each time, which shows
 * up in somebody's calendar as a notification storm and in ours as nothing at
 * all. So the UID is derived from the row's identity and never from its
 * contents.
 */
final class VesselFeed
{
    /**
     * How far back the feed reaches.
     *
     * A month. A subscriber wants to know the boat is busy *now* and soon; last
     * August's sailings make the file larger for every consumer for ever and
     * answer no question anybody has. Not zero, because a charter that started
     * yesterday and ends tomorrow must still appear.
     */
    private const PAST_DAYS = 31;

    /** How far forward. A season plus change. */
    private const FUTURE_DAYS = 400;

    public function __construct(private readonly IcalFeed $feed) {}

    /**
     * The `.ics` body.
     *
     * Built through `sabre/vobject` rather than by hand. Line folding at 75
     * octets, escaping commas and semicolons inside a value, and CRLF endings
     * are all things a hand-rolled writer gets *almost* right — and an almost
     * correct feed is one Google accepts and Outlook silently drops half of.
     */
    public function render(?Carbon $now = null): string
    {
        $now ??= Carbon::now();

        $calendar = new VCalendar([
            'PRODID' => '-//Kaiki//Vessel calendar//EN',
            'VERSION' => '2.0',
            'CALSCALE' => 'GREGORIAN',
            // A publish-only feed. Without it some clients offer the operator's
            // subscriber a "decline" button on somebody else's boat.
            'METHOD' => 'PUBLISH',
        ]);

        $vessel = $this->feed->vessel;

        $calendar->add('X-WR-CALNAME', (string) ($vessel?->name));
        $calendar->add('X-WR-TIMEZONE', $this->timezone());

        $from = $now->copy()->subDays(self::PAST_DAYS);
        $until = $now->copy()->addDays(self::FUTURE_DAYS);

        if ($this->feed->include_blocks) {
            foreach ($this->blocks($from, $until) as $block) {
                $calendar->add('VEVENT', $this->event(
                    'block-' . $block->getKey(),
                    $block->starts_at_utc,
                    $block->ends_at_utc,
                    (string) __('ical.summary.blocked'),
                    $block->updated_at ?? $now,
                ));
            }
        }

        if ($this->feed->include_departures) {
            foreach ($this->departures($from, $until) as $departure) {
                $calendar->add('VEVENT', $this->event(
                    'departure-' . $departure->getKey(),
                    $departure->starts_at_utc,
                    $departure->ends_at_utc,
                    (string) __('ical.summary.booked'),
                    $departure->updated_at ?? $now,
                ));
            }
        }

        return $calendar->serialize();
    }

    /**
     * A filename a subscriber's downloads folder can live with.
     *
     * `slug()` transliterates, so a Greek vessel name survives a Windows file
     * system and an email attachment.
     */
    public function filename(): string
    {
        $name = str((string) $this->feed->vessel?->name)->slug()->value();

        return ($name === '' ? 'vessel' : $name) . '.ics';
    }

    /**
     * @return Collection<int, VesselBlock>
     */
    private function blocks(Carbon $from, Carbon $until)
    {
        return VesselBlock::query()
            ->where('vessel_id', $this->feed->vessel_id)
            ->where('starts_at_utc', '<', $until)
            ->where('ends_at_utc', '>', $from)
            ->orderBy('starts_at_utc')
            ->get();
    }

    /**
     * Sailings with somebody on them (OPS-13: *"departures with sold seats"*).
     *
     * `seats_sold`, not `seats_sold + seats_held`. A hold is fifteen minutes of
     * somebody thinking about it, and publishing it would make the boat look
     * busy in an external calendar for a quarter of an hour, then not — which
     * is exactly the flapping that teaches a subscriber to ignore the feed.
     *
     * Cancelled sailings are excluded: a cancelled trip is not an occupation,
     * and leaving it in tells a subscriber the boat is busy when it is free.
     *
     * @return Collection<int, Departure>
     */
    private function departures(Carbon $from, Carbon $until)
    {
        return Departure::query()
            ->where('vessel_id', $this->feed->vessel_id)
            ->where('seats_sold', '>', 0)
            ->whereNotIn('status', [
                DepartureStatus::Cancelled->value,
            ])
            ->where('starts_at_utc', '<', $until)
            ->where('ends_at_utc', '>', $from)
            ->orderBy('starts_at_utc')
            ->get();
    }

    /**
     * One `VEVENT`.
     *
     * `TRANSP: OPAQUE` is what actually makes a subscriber treat the period as
     * busy rather than as a note in the margin — the entire purpose of this
     * feed. `DTSTAMP` is the row's own `updated_at`, so a client that diffs on
     * it sees a change only when something changed.
     *
     * Returns the properties rather than adding them, so this class never has
     * to describe `VCalendar`'s own iterable shape to the analyser — and so the
     * two callers below read as a list of events rather than as side effects.
     *
     * `\Carbon\Carbon` rather than Laravel's subclass: a model's `updated_at`
     * and a `Carbon::now()` are different classes and both arrive here.
     *
     * @return array<string, mixed>
     */
    private function event(
        string $uid,
        CarbonBase $startUtc,
        CarbonBase $endUtc,
        string $summary,
        CarbonBase $stamp,
    ): array {
        return [
            'UID' => $uid . '@' . $this->uidDomain(),
            'DTSTAMP' => $stamp->copy()->utc()->toDateTime(),
            'DTSTART' => $startUtc->copy()->utc()->toDateTime(),
            'DTEND' => $endUtc->copy()->utc()->toDateTime(),
            'SUMMARY' => $summary,
            'TRANSP' => 'OPAQUE',
        ];
    }

    /**
     * The right-hand side of every UID.
     *
     * The application's own host, so two Kaiki installations — production and a
     * staging copy of the same database — cannot produce colliding UIDs in a
     * calendar somebody subscribes to twice.
     */
    private function uidDomain(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'kaiki.app';
    }

    private function timezone(): string
    {
        $timezone = $this->feed->tenant?->timezone;

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('kaiki.defaults.timezone', 'Europe/Athens');
    }
}
