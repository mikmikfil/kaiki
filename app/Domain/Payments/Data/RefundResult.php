<?php

declare(strict_types=1);

namespace App\Domain\Payments\Data;

use App\Contracts\PaymentGateway;
use Spatie\LaravelData\Data;

/**
 * What came back from asking a gateway to give money back (ADR-0004.2).
 *
 * ## A refusal is a result, not an exception
 *
 * The same posture `VerificationResult` takes for credentials (#79), and for
 * the same reason: a gateway declining a refund — the charge is too old, the
 * balance is short, the payment was already refunded — is an ordinary outcome
 * that an operator needs to be *told about*, not a 500 in the middle of a
 * cancellation workflow.
 *
 * Exceptions are reserved for the gateway being unreachable, which is a
 * different problem with a different remedy.
 *
 * ## `code` is the gateway's, and never shown to anybody
 *
 * It is fed to {@see PaymentGateway::describeError()}, which is
 * what produces the two sentences a person reads. PAY-12: raw gateway text
 * never reaches a guest.
 */
final class RefundResult extends Data
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly int $refundedCents,
        public readonly ?string $reference = null,
        public readonly ?string $code = null,
    ) {}

    public static function success(int $refundedCents, string $reference): self
    {
        return new self(succeeded: true, refundedCents: $refundedCents, reference: $reference);
    }

    /** @param  string  $code  the gateway's own code, for `describeError()` */
    public static function failure(string $code): self
    {
        return new self(succeeded: false, refundedCents: 0, code: $code);
    }
}
