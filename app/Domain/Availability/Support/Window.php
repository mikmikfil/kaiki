<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use Illuminate\Support\Carbon;

/**
 * A UTC interval, and the AVL-7 conflict predicate (spec AVL-7 to AVL-9).
 *
 * ## The predicate, written once
 *
 * AVL-7, fixed: two occupations on the same vessel conflict **if and only if**
 *
 *     A.start < B.end + buffer  and  B.start < A.end + buffer
 *
 * It is symmetric and the buffer is counted **once**, not once per side. That
 * detail is the whole reason this lives in one place: the obvious mistake is to
 * pad both windows, which doubles the required gap and quietly makes a boat
 * look busy for two hours between two one-hour-buffered trips. The operator
 * then rings support about departures that will not generate.
 *
 * ## The buffer is applied here, never stored
 *
 * AVL-8: the buffer comes from the current vessel setting at query time and is
 * never baked into `starts_at_utc` / `ends_at_utc`. So an operator who lowers
 * their turnaround from 60 to 30 minutes sees the change in future availability
 * immediately, and no existing row is rewritten.
 *
 * ## Everything is UTC
 *
 * AVL-13: interval logic compares UTC instants. A window built from local times
 * would be wrong by an hour twice a year, on exactly the days a night charter
 * is most likely to be booked.
 */
final class Window
{
    public function __construct(
        public readonly Carbon $startUtc,
        public readonly Carbon $endUtc,
    ) {}

    public static function of(Carbon $start, Carbon $end): self
    {
        return new self($start->copy()->utc(), $end->copy()->utc());
    }

    /**
     * Do these two occupations conflict, given a turnaround buffer?
     *
     * The gap between them must be **at least** the buffer. A gap exactly equal
     * to it is legal — the boat has had its turnaround — which is why the
     * comparison is strict on both sides. One minute less is a conflict, and
     * TST-5 requires the boundary and one minute either side to be tested.
     */
    public function conflictsWith(self $other, int $bufferMinutes): bool
    {
        $buffer = max(0, $bufferMinutes);

        return $this->startUtc->lessThan($other->endUtc->copy()->addMinutes($buffer))
            && $other->startUtc->lessThan($this->endUtc->copy()->addMinutes($buffer));
    }

    /** Plain overlap, with no turnaround allowance. */
    public function overlaps(self $other): bool
    {
        return $this->conflictsWith($other, 0);
    }

    /** How long this window is, in whole minutes of elapsed time. */
    public function minutes(): int
    {
        return (int) round(($this->endUtc->getTimestamp() - $this->startUtc->getTimestamp()) / 60);
    }

    /**
     * This window widened by the buffer on both sides, for a **range query**.
     *
     * Used to narrow a database scan before the exact predicate runs in PHP.
     * Deliberately not the predicate itself: padding both sides doubles the
     * effective gap, which is wrong as an answer and right as a filter — it can
     * only return more candidates than needed, never fewer.
     */
    public function paddedBy(int $bufferMinutes): self
    {
        $buffer = max(0, $bufferMinutes);

        return new self(
            $this->startUtc->copy()->subMinutes($buffer),
            $this->endUtc->copy()->addMinutes($buffer),
        );
    }
}
