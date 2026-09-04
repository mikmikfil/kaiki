<?php

declare(strict_types=1);

namespace App\Data\Pricing;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * A price, not yet a booking (spec PRC-1, PRC-15, PRC-23).
 *
 * What `POST /price-quote` returns in #37 and what M2 persists when a booking
 * is taken. **Never persisted by this milestone** — PRC-15 makes a quote a
 * calculation with a shelf life, and `expiresAt` is that shelf life.
 *
 * The deposit and the balance sit beside the snapshot rather than inside it
 * because they are what the guest is asked to pay *now*, which is a question
 * about the checkout rather than about the derivation. The snapshot still
 * carries its own `deposit` block, because §3.4 requires the amount to be
 * explainable a year later.
 */
final class PriceQuoteData extends Data
{
    public function __construct(
        public readonly PriceSnapshotData $snapshot,
        public readonly int $totalCents,
        public readonly int $depositCents,
        public readonly int $balanceCents,
        public readonly Carbon $expiresAt,
        /** Extras the guest asked for that carry no price (PRC-9). */
        public readonly bool $hasOnRequestItems = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total_cents' => $this->totalCents,
            'deposit_cents' => $this->depositCents,
            'balance_cents' => $this->balanceCents,
            'expires_at' => $this->expiresAt->utc()->toIso8601ZuluString(),
            'has_on_request_items' => $this->hasOnRequestItems,
            'price_snapshot' => $this->snapshot->toArray(),
        ];
    }
}
