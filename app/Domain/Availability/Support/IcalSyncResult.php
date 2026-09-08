<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

/**
 * What one calendar sync did (spec OPS-15).
 *
 * A value object rather than an array or a bare count, because the panel and
 * the tests both need to tell four outcomes apart that all look like "nothing
 * happened" from the outside:
 *
 * - **failed** — we could not read the source, and no block was touched.
 * - **unchanged** — a 304; the source says nothing moved.
 * - **zero of everything** — the feed parsed and matched what we already had.
 * - **removed only** — events disappeared at the source.
 *
 * Collapsing the first two into "0 imported" is how an operator ends up staring
 * at a source that has silently not worked for a fortnight.
 */
final class IcalSyncResult
{
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $removed = 0,
        public readonly int $total = 0,
        public readonly bool $failed = false,
        public readonly bool $unchanged = false,
    ) {}

    public function succeeded(): bool
    {
        return ! $this->failed;
    }

    /** Did this sync change any block at all? */
    public function changedAnything(): bool
    {
        return $this->created > 0 || $this->updated > 0 || $this->removed > 0;
    }
}
