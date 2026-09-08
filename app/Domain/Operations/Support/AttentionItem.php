<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use Illuminate\Support\Carbon;

/**
 * One thing wanting a decision (spec OPS-1).
 *
 * A value object rather than an array, because the panel sorts on `deadline`
 * and an array would let a caller sort on the wrong key without anything
 * saying so.
 */
final class AttentionItem
{
    public function __construct(
        /** Stable across renders, so a polling panel does not re-key its rows. */
        public readonly string $key,
        public readonly AttentionSeverity $severity,
        public readonly string $title,
        public readonly string $detail,
        /** When the chance to act runs out. Null when nothing is counting down. */
        public readonly ?Carbon $deadline,
    ) {}

    /**
     * The sort key: soonest deadline first, undated last.
     *
     * `PHP_INT_MAX` rather than zero for a null deadline. Zero would sort an
     * item with no clock *above* a boat leaving in three hours, which is the
     * exact inversion this list exists to avoid.
     */
    public function deadlineSortKey(): int
    {
        return $this->deadline?->getTimestamp() ?? PHP_INT_MAX;
    }

    /** Has the moment to act already passed? */
    public function isOverdue(?Carbon $now = null): bool
    {
        return $this->deadline !== null && $this->deadline->isBefore($now ?? Carbon::now());
    }
}
