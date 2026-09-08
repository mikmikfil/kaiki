<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Availability\Support\LocalDay;
use App\Domain\Availability\Support\Window;
use App\Domain\Availability\VesselCalendar;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One local day of the fleet, as bars on a track (spec OPS-3, OPS-4).
 *
 * Everything the calendar draws is computed here and nothing is computed in the
 * template. A Blade file doing interval arithmetic is a Blade file nobody tests,
 * and the arithmetic is where this gets subtly wrong:
 *
 * **Fractions of the day, not of 24 hours.** The last Sunday of March in Athens
 * is 23 hours long. A bar positioned as `hours / 24` on that day is drawn in the
 * wrong place — by a whole hour, on one of the two days most likely to carry an
 * odd charter. {@see LocalDay} already knows how long the day is; this divides
 * by that.
 *
 * **Bars are clipped, not dropped.** An overnight charter starting at 22:00 and
 * ending at 09:00 belongs on both days, running off the right edge of the first
 * and onto the left edge of the second. Dropping it from the second would show
 * an operator a free boat that is at sea.
 *
 * ## OPS-4: the turnaround is drawn
 *
 * The availability engine refuses an overlapping booking because of a buffer
 * the operator cannot see, and *"why can't I book this, the boat is free"* is
 * the support call that follows. The buffer is a distinct margin after each bar,
 * from the vessel's own `turnaround_buffer_minutes`, so the refusal is
 * explicable without documentation.
 *
 * It is drawn and never stored (AVL-8): an operator who lowers their turnaround
 * sees shorter margins on the same rows immediately.
 */
final class CalendarDay
{
    /**
     * @param  list<array{vessel: Vessel, bars: list<array<string, mixed>>}>  $rows
     */
    private function __construct(
        public readonly string $localDate,
        public readonly string $timezone,
        public readonly array $rows,
    ) {}

    public static function for(string $localDate, string $timezone): self
    {
        $day = LocalDay::of($localDate, $timezone);
        $window = Window::of($day->startUtc, $day->endUtcExclusive);
        $minutes = $day->hours() * 60;

        /** @var Collection<int, Vessel> $vessels */
        $vessels = Vessel::query()->orderBy('sort_order')->orderBy('id')->get();

        $drawn = VesselCalendar::toDraw($vessels, $window);

        $rows = [];

        foreach ($vessels as $vessel) {
            $key = (int) $vessel->getKey();
            $buffer = $vessel->effectiveTurnaroundBufferMinutes();

            $bars = [];

            foreach ($drawn['departures']->where('vessel_id', $key) as $departure) {
                $bars[] = self::departureBar($departure, $day, $minutes, $buffer);
            }

            foreach ($drawn['blocks']->where('vessel_id', $key) as $block) {
                $bars[] = self::blockBar($block, $day, $minutes, $buffer);
            }

            usort($bars, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

            $rows[] = ['vessel' => $vessel, 'bars' => $bars];
        }

        return new self($day->localDate, $timezone, $rows);
    }

    /** Is there anything at all on this day? */
    public function isEmpty(): bool
    {
        foreach ($this->rows as $row) {
            if ($row['bars'] !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The hour labels down the track.
     *
     * Derived from the day's real length rather than assumed, so the 25-hour
     * day in October gets 25 of them and they land in the right places.
     *
     * @return list<array{label: string, at: float}>
     */
    public function hours(): array
    {
        $day = LocalDay::of($this->localDate, $this->timezone);
        $count = $day->hours();

        $marks = [];

        for ($hour = 0; $hour <= $count; $hour++) {
            $marks[] = [
                'label' => $day->startUtc->copy()->addHours($hour)->setTimezone($this->timezone)->format('H:i'),
                'at' => $hour / $count,
            ];
        }

        return $marks;
    }

    /**
     * Where "now" falls across the track, or null when this is not today.
     *
     * ## The same divisor as every bar, deliberately
     *
     * A marker positioned by `hour / 24` beside bars positioned by the day's
     * **real** length drifts by up to an hour on the two days a year the clocks
     * move — and it drifts in the most misleading way available, because the
     * marker is the thing an operator reads the bars *against*. So it goes
     * through `LocalDay` like everything else in this class.
     *
     * Null off today rather than clamped to an edge: a line pinned to midnight
     * on a day the operator is looking *ahead* to reads as an occupation, and a
     * marker that lies about the time is worse than no marker.
     */
    public function nowFraction(?Carbon $now = null): ?float
    {
        $day = LocalDay::of($this->localDate, $this->timezone);
        $instant = ($now ?? Carbon::now())->copy()->utc();

        if ($instant->lessThan($day->startUtc) || $instant->greaterThanOrEqualTo($day->endUtcExclusive)) {
            return null;
        }

        $length = $day->startUtc->diffInSeconds($day->endUtcExclusive);

        return $length > 0 ? $day->startUtc->diffInSeconds($instant) / $length : null;
    }

    /**
     * @return array{kind: string, uuid: string, label: string, detail: string|null, start: float, end: float, buffer: float, pax: int|null, capacity: int|null, cancelled: bool, reason: string|null}
     */
    private static function departureBar(Departure $departure, LocalDay $day, int $minutes, int $buffer): array
    {
        return [
            'kind' => 'departure',
            'uuid' => $departure->uuid,
            'label' => (string) $departure->product->title,
            'detail' => $departure->local_time,
            'start' => self::fraction($departure->starts_at_utc, $day, $minutes),
            'end' => self::fraction($departure->ends_at_utc, $day, $minutes),
            'buffer' => $minutes > 0 ? $buffer / $minutes : 0.0,
            'pax' => $departure->seats_sold,
            'capacity' => $departure->capacity,
            // Cancelled departures stay on the calendar, faintly. A trip that
            // vanished the moment it was cancelled reads as a mistake, and the
            // operator wants to see that it *was* cancelled.
            'cancelled' => $departure->status === DepartureStatus::Cancelled,
            'reason' => null,
        ];
    }

    /**
     * @return array{kind: string, uuid: string, label: string, detail: string|null, start: float, end: float, buffer: float, pax: int|null, capacity: int|null, cancelled: bool, reason: string|null}
     */
    private static function blockBar(VesselBlock $block, LocalDay $day, int $minutes, int $buffer): array
    {
        return [
            'kind' => 'block',
            'uuid' => (string) $block->getKey(),
            'label' => $block->title ?? $block->reason->label(),
            'detail' => $block->is_all_day ? null : $block->starts_at_utc->setTimezone($day->timezone)->format('H:i'),
            'start' => self::fraction($block->starts_at_utc, $day, $minutes),
            'end' => self::fraction($block->ends_at_utc, $day, $minutes),
            'buffer' => $minutes > 0 ? $buffer / $minutes : 0.0,
            'pax' => null,
            'capacity' => null,
            'cancelled' => false,
            'reason' => $block->reason->value,
        ];
    }

    /**
     * Where an instant falls in the day, as a fraction, clamped to its edges.
     *
     * The clamp is what makes an overnight charter appear on both days rather
     * than on neither: its start on the second day is before midnight, which is
     * a negative fraction, and 0.0 is what "runs on from yesterday" looks like
     * on a track.
     */
    private static function fraction(Carbon $instant, LocalDay $day, int $minutes): float
    {
        if ($minutes <= 0) {
            return 0.0;
        }

        $offset = ($instant->getTimestamp() - $day->startUtc->getTimestamp()) / 60;

        return max(0.0, min(1.0, $offset / $minutes));
    }
}
