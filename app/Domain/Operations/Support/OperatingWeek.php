<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Availability\Support\LocalDay;
use Illuminate\Support\Carbon;

/**
 * The operator's week, Monday to Monday, as a half-open UTC interval
 * (spec OPS-2).
 *
 * A class rather than `startOfWeek()` at a call site, for the three reasons that
 * make a week harder than it looks:
 *
 * **It is the tenant's week, not the server's.** An operator in Athens closes
 * their week at midnight in Athens. A figure computed in UTC is wrong for two or
 * three hours every Sunday night — which is exactly when somebody sitting down
 * with the week's numbers would look at it.
 *
 * **It starts on Monday.** Carbon's default first day of the week follows the
 * locale, and a figure whose boundary moves when somebody changes an app config
 * is a figure that silently disagrees with last week's copy of itself.
 *
 * **It is not always 168 hours.** The last Sunday of March is 23 hours long in
 * Europe/Athens and the last Sunday of October is 25. {@see LocalDay} already
 * carries that reasoning; this composes two of them rather than adding seven
 * days of seconds to an instant.
 *
 * Half-open — `>= start && < end` — so consecutive weeks compose with no gap and
 * no double count, and no payment can land in two of them.
 */
final class OperatingWeek
{
    private function __construct(
        public readonly Carbon $startUtc,
        public readonly Carbon $endUtcExclusive,
        public readonly string $startLocalDate,
        public readonly string $timezone,
    ) {}

    /**
     * The week containing `$within`, or containing now.
     *
     * `$within` is a *local* date in the tenant's timezone. Passing an instant
     * and letting this decide which local day it falls in would be the same
     * mistake twice — the caller already knows whether it is holding a date or a
     * moment, and this signature makes it say so.
     */
    public static function containing(string $timezone, ?string $within = null): self
    {
        $date = $within ?? Carbon::now($timezone)->toDateString();

        // `startOfWeek(Carbon::MONDAY)` explicitly rather than by default: the
        // default follows the application locale, and el_GR and en_US disagree.
        $monday = Carbon::parse($date, $timezone)->startOfWeek(Carbon::MONDAY)->toDateString();
        $next = Carbon::parse($monday, $timezone)->addWeek()->toDateString();

        return new self(
            LocalDay::of($monday, $timezone)->startUtc,
            LocalDay::of($next, $timezone)->startUtc,
            $monday,
            $timezone,
        );
    }

    /** Half-open: the first instant of next Monday is not in this week. */
    public function contains(Carbon $instantUtc): bool
    {
        $instant = $instantUtc->copy()->utc();

        return $instant->greaterThanOrEqualTo($this->startUtc)
            && $instant->lessThan($this->endUtcExclusive);
    }

    /** How long this week actually is: 167, 168 or 169 hours. */
    public function hours(): int
    {
        return (int) round(($this->endUtcExclusive->getTimestamp() - $this->startUtc->getTimestamp()) / 3600);
    }
}
