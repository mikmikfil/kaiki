<?php

declare(strict_types=1);

namespace App\Data\Availability;

use Spatie\LaravelData\Data;

/**
 * What one generation run did (ADR-0009, ADR-0016).
 *
 * Three counts and a list, and the list is the interesting part: dates the rule
 * matched but which have **no valid local time** on account of the DST spring
 * forward. ADR-0016 Option A refuses to invent a departure there, and requires
 * the operator be told rather than left with a silent gap in their calendar.
 *
 * `skipped` counts dates that already had a departure — the ordinary outcome of
 * a second run, and the number that proves idempotency in a test far more
 * directly than a row count does.
 */
final class GenerationResultData extends Data
{
    /**
     * @param  list<string>  $dstSkippedDates  local dates with no such local time
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $skipped = 0,
        public readonly array $dstSkippedDates = [],
    ) {}

    public function hasIssues(): bool
    {
        return $this->dstSkippedDates !== [];
    }
}
