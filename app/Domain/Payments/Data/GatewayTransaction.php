<?php

declare(strict_types=1);

namespace App\Domain\Payments\Data;

/**
 * What a gateway says about one payment, reduced to what we act on.
 *
 * Three states, because that is how many there are to act on: the money is
 * there, the attempt is over and it is not, or neither is settled yet. A
 * gateway's own vocabulary is richer — Viva alone distinguishes finished,
 * active, cancelled, error and refunded — and every one of those words maps
 * onto one of these three decisions or onto "wait".
 *
 * The amount travels with it because it is the thing that must be checked
 * rather than assumed: a re-fetch that confirms a booking without comparing what
 * was actually taken to what was owed is the same mistake as trusting a webhook
 * body, made one step later.
 */
final readonly class GatewayTransaction
{
    private function __construct(
        public bool $succeeded,
        public bool $settled,
        public int $amountCents,
        public ?string $transactionId = null,
    ) {}

    /**
     * The money is there.
     *
     * `$transactionId` is the gateway's id for the charge itself, as opposed
     * to the order it was made against: a refund reverses the charge, so it is
     * what the refund call needs (2026-09-25).
     */
    public static function paid(int $amountCents, ?string $transactionId = null): self
    {
        return new self(succeeded: true, settled: true, amountCents: $amountCents, transactionId: $transactionId);
    }

    /** The attempt is over and the money is not coming. */
    public static function failed(int $amountCents = 0): self
    {
        return new self(succeeded: false, settled: true, amountCents: $amountCents);
    }

    /** Still in flight — the guest may be on the 3-D Secure step right now. */
    public static function pending(int $amountCents = 0): self
    {
        return new self(succeeded: false, settled: false, amountCents: $amountCents);
    }
}
