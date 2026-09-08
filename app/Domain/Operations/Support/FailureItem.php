<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Enums\FailureSource;
use Illuminate\Support\Carbon;

/**
 * One thing the product tried to do and could not (spec OPS-21, NFR-8).
 *
 * A value object rather than an array, for the reason {@see AttentionItem}
 * gives: the feed sorts on `failedAt` and merges six sources, and an array
 * would let one of them supply a differently-shaped row that sorts wrongly
 * without anything saying so.
 *
 * ## `explanation` is a sentence, not a code
 *
 * NFR-8: *"failures are visible in the operator panel with human-readable Greek
 * messages."* The provider's own string — `bounced_hard`, `HTTP 500`,
 * `card_declined` — is kept in `detail` for somebody who wants it, and the
 * explanation beside it says what it means and what to do. An operator reading
 * `SMTP 550 5.1.1` at seven in the morning learns nothing.
 *
 * ## The booking is a reference, not a model
 *
 * OPS-21 asks for *"the affected booking"*. Carrying the model would mean six
 * queries per row and a feed that eager-loads across six unrelated tables; the
 * reference is what an operator searches on, and it is what a row can carry
 * without knowing anything about how the others are shaped.
 */
final class FailureItem
{
    public function __construct(
        public readonly FailureSource $source,

        /** The row's own id in its own table — what the retry dispatches against. */
        public readonly int $id,

        /** Stable across renders, so a polling page does not re-key its rows. */
        public readonly string $key,

        public readonly Carbon $failedAt,

        /** What failed, in a few words. */
        public readonly string $title,

        /** What it means and what to do, in the operator's language. */
        public readonly string $explanation,

        /** The provider's own words, for whoever wants them. Null when there were none. */
        public readonly ?string $detail = null,

        /** The affected booking's reference, when there is one. */
        public readonly ?string $bookingReference = null,

        /** Can the operator press "try again" on this row? */
        public readonly bool $retryable = false,
    ) {}

    public function failedAtSortKey(): int
    {
        return $this->failedAt->getTimestamp();
    }
}
