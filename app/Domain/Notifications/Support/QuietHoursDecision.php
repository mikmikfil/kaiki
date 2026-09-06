<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Support;

use Illuminate\Support\Carbon;

/**
 * BKG-18's three answers, as one value.
 *
 * "Send now", "send at 08:00" and "drop it" are three outcomes, and a nullable
 * `Carbon` can only carry two of them. The third is the one BKG-18 spends a
 * clause on — *"dropped and logged"* — and a null with a comment beside it is
 * exactly how the log part gets forgotten.
 */
final class QuietHoursDecision
{
    private function __construct(
        public readonly bool $deliver,
        public readonly ?Carbon $at,
    ) {}

    public static function sendAt(Carbon $at): self
    {
        return new self(deliver: true, at: $at);
    }

    /** Dropped, because 08:00 would be after the thing it warns about. */
    public static function drop(): self
    {
        return new self(deliver: false, at: null);
    }

    /** Was this held back from the night and moved to the morning? */
    public function wasDeferred(Carbon $wanted): bool
    {
        return $this->deliver && $this->at !== null && $this->at->greaterThan($wanted);
    }
}
