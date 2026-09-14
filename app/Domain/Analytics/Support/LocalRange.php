<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

use App\Domain\Availability\Support\LocalDay;
use App\Domain\Operations\Support\OperatingWeek;
use Illuminate\Support\Carbon;

/**
 * A span of local calendar days as a half-open UTC interval.
 *
 * {@see LocalDay} for one day and {@see OperatingWeek}
 * for one week already carry this reasoning; a statistics screen asks for
 * arbitrary spans — last month, this season, the year — and the same three
 * traps are waiting in each of them:
 *
 * **It is the operator's calendar, not the server's.** A month that ends at
 * midnight UTC ends at three in the morning in Athens, so a booking taken at
 * one o'clock on the first lands in the month before. Somebody reconciling
 * August against their bank finds that, once, and then stops trusting the page.
 *
 * **A day is not always 24 hours.** Two days a year are 23 and 25 in Athens, so
 * a range is composed out of `LocalDay`s rather than built by adding seconds.
 *
 * **Half-open, so two ranges compose.** `>= start && < end` — which is what
 * lets "the previous period" sit exactly against this one with no gap and no
 * payment counted twice.
 *
 * ## It also knows what to compare itself against
 *
 * A number on its own is not information: €8,400 is good or bad depending on
 * what the same days were worth last time. {@see self::previous()} is the same
 * number of days immediately before, and {@see self::lastYear()} is the same
 * dates a year earlier — which for a seasonal business is usually the more
 * honest of the two, because the previous period of a boat operator's October
 * is September.
 */
final class LocalRange
{
    /** Bucket sizes for a series, chosen by how long the range is. */
    public const GRAIN_DAY = 'day';

    public const GRAIN_WEEK = 'week';

    public const GRAIN_MONTH = 'month';

    private function __construct(
        public readonly Carbon $startUtc,
        public readonly Carbon $endUtcExclusive,
        public readonly string $startLocalDate,
        public readonly string $endLocalDate,
        public readonly string $timezone,
    ) {}

    /**
     * The interval covering every local day from `$from` to `$to` inclusive.
     *
     * Inclusive in the arguments and half-open in the result, because those are
     * the two different audiences: an operator picking "1 to 31 August" means
     * both ends, and a `where` clause needs the boundary to belong to exactly
     * one side.
     */
    public static function between(Carbon|string $from, Carbon|string $to, string $timezone): self
    {
        $first = LocalDay::of($from, $timezone);
        $last = LocalDay::of($to, $timezone);

        // A range given backwards is a slip, not an empty range: an empty page
        // would leave the operator wondering which of their two dates was
        // wrong. Swapping is the reading they meant.
        if ($last->startUtc->lessThan($first->startUtc)) {
            [$first, $last] = [$last, $first];
        }

        return new self(
            $first->startUtc,
            $last->endUtcExclusive,
            $first->localDate,
            $last->localDate,
            $timezone,
        );
    }

    /** The `$days` local days ending today, today included. */
    public static function lastDays(int $days, string $timezone): self
    {
        $today = Carbon::now($timezone)->toDateString();
        $from = Carbon::parse($today)->subDays(max(1, $days) - 1)->toDateString();

        return self::between($from, $today, $timezone);
    }

    /** The calendar month containing `$within`, or the current one. */
    public static function month(string $timezone, ?string $within = null): self
    {
        $anchor = Carbon::parse($within ?? Carbon::now($timezone)->toDateString());

        return self::between(
            $anchor->copy()->startOfMonth()->toDateString(),
            $anchor->copy()->endOfMonth()->toDateString(),
            $timezone,
        );
    }

    /** The calendar year containing `$within`, or the current one. */
    public static function year(string $timezone, ?string $within = null): self
    {
        $anchor = Carbon::parse($within ?? Carbon::now($timezone)->toDateString());

        return self::between(
            $anchor->copy()->startOfYear()->toDateString(),
            $anchor->copy()->endOfYear()->toDateString(),
            $timezone,
        );
    }

    /** How many local days this range covers. */
    public function days(): int
    {
        return (int) Carbon::parse($this->startLocalDate)->diffInDays(Carbon::parse($this->endLocalDate)) + 1;
    }

    /** The same number of days, immediately before this range. */
    public function previous(): self
    {
        $days = $this->days();
        $end = Carbon::parse($this->startLocalDate)->subDay();

        return self::between($end->copy()->subDays($days - 1)->toDateString(), $end->toDateString(), $this->timezone);
    }

    /**
     * The same dates, a year earlier.
     *
     * Dates rather than "365 days ago", so August is compared with August. The
     * 29th of February falls back to the 28th the way Carbon does, which is one
     * day of error once every four years in a figure nobody reconciles to the
     * cent.
     */
    public function lastYear(): self
    {
        return self::between(
            Carbon::parse($this->startLocalDate)->subYear()->toDateString(),
            Carbon::parse($this->endLocalDate)->subYear()->toDateString(),
            $this->timezone,
        );
    }

    /**
     * How finely a chart of this range should be bucketed.
     *
     * A year of daily points is 365 columns two pixels wide, which is a texture
     * rather than a chart. The thresholds are where the shape stops being
     * readable rather than round numbers: about ten weeks of days, about two
     * years of weeks.
     */
    public function grain(): string
    {
        return match (true) {
            $this->days() <= 70 => self::GRAIN_DAY,
            $this->days() <= 730 => self::GRAIN_WEEK,
            default => self::GRAIN_MONTH,
        };
    }

    /**
     * Every local date in the range, in order.
     *
     * The series is built by filling this list rather than by taking whatever
     * the database grouped — a day with no bookings is a real zero, and a chart
     * that silently omits it draws a straight line through a quiet Tuesday.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];
        $cursor = Carbon::parse($this->startLocalDate);
        $last = Carbon::parse($this->endLocalDate);

        while ($cursor->lessThanOrEqualTo($last)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * The bucket a local date belongs to, at this range's grain.
     *
     * Weeks start on Monday, for the reason `OperatingWeek` gives: a boundary
     * that follows the locale is a boundary that moves when somebody changes a
     * config, and last week's chart stops matching its own copy.
     */
    public function bucketOf(string $localDate): string
    {
        $date = Carbon::parse($localDate);

        return match ($this->grain()) {
            self::GRAIN_DAY => $date->toDateString(),
            self::GRAIN_WEEK => $date->startOfWeek(Carbon::MONDAY)->toDateString(),
            default => $date->startOfMonth()->toDateString(),
        };
    }

    /**
     * The buckets this range covers, in order, each empty.
     *
     * @return array<string, null>
     */
    public function buckets(): array
    {
        $buckets = [];

        foreach ($this->dates() as $date) {
            $buckets[$this->bucketOf($date)] = null;
        }

        return $buckets;
    }
}
