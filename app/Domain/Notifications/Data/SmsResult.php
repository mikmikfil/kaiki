<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

use Spatie\LaravelData\Data;

/**
 * What came back from asking a provider to send a text (spec NTF-2, NTF-3).
 *
 * The same posture `RefundResult` and `VerificationResult` take, and for the
 * same reason: a refusal is an outcome an operator needs to read, not an
 * exception to be caught three layers up.
 *
 * `costCents` is nullable rather than zero. A provider that does not report a
 * price records **nothing**, because zero is a number an operator would
 * reasonably add up — and a season of free-looking messages that actually cost
 * money is a bill nobody expected.
 */
final class SmsResult extends Data
{
    private function __construct(
        public readonly bool $sent,
        public readonly ?string $reference = null,
        public readonly ?int $costCents = null,
        public readonly ?string $error = null,
    ) {}

    public static function sent(string $reference, ?int $costCents = null): self
    {
        return new self(sent: true, reference: $reference, costCents: $costCents);
    }

    /** @param  string  $error  the provider's own words; never shown to a guest */
    public static function failed(string $error): self
    {
        return new self(sent: false, error: $error);
    }

    /**
     * Composed and deliberately not delivered.
     *
     * The null gateway's answer. Recorded as **sent** because the platform did
     * everything it was asked to do; the `provider` column is what says the
     * message went nowhere, and NTF-2 calls that a fallback rather than a
     * failure.
     */
    public static function swallowed(): self
    {
        return new self(sent: true, reference: 'null:' . bin2hex(random_bytes(8)));
    }
}
