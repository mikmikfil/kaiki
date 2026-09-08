<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Data;

/**
 * What AADE said (spec MYD-5, MYD-9, MYD-15).
 *
 * ## Three outcomes, and the middle one is the one people forget
 *
 * **Accepted** — a MARK came back, and MYD-5 says that and only that moves an
 * invoice to `sent`.
 *
 * **Refused** — AADE answered, and the answer is no. A malformed ΑΦΜ, a VAT
 * category that does not exist, a document that duplicates one already
 * registered. Retrying changes nothing: the payload is wrong and will be wrong
 * in six hours. This must **stop** the retry ladder, or an operator's failure
 * feed fills with eight identical attempts at something that was never going to
 * work.
 *
 * **Unreachable** — no answer at all. A timeout, a 502, AADE's Saturday
 * maintenance. The payload may be perfect. This must **continue** the ladder.
 *
 * Collapsing refused and unreachable into one "failed" is the mistake that makes
 * a retry queue useless in both directions: it retries what cannot succeed and,
 * once somebody notices, stops retrying what would have.
 *
 * ## Nothing here carries a credential
 *
 * MYD-15: *"myDATA payloads and credentials are never logged."* This object is
 * written to `invoices.response_payload` and appears in job context, so a field
 * holding a subscription key would put it in both. The raw body is stored
 * redacted by the gateway before it ever reaches here.
 */
final readonly class MyDataResult
{
    /**
     * @param  string|null  $mark  the AADE MARK — the only thing that means "registered"
     * @param  string|null  $rawResponse  redacted by the gateway, for support
     */
    private function __construct(
        public bool $accepted,
        public bool $retryable,
        public ?string $mark = null,
        public ?string $uid = null,
        public ?string $authenticationCode = null,
        public ?string $qrUrl = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $rawResponse = null,
    ) {}

    public static function accepted(
        string $mark,
        ?string $uid = null,
        ?string $authenticationCode = null,
        ?string $qrUrl = null,
        ?string $rawResponse = null,
    ): self {
        return new self(
            accepted: true,
            retryable: false,
            mark: $mark,
            uid: $uid,
            authenticationCode: $authenticationCode,
            qrUrl: $qrUrl,
            rawResponse: $rawResponse,
        );
    }

    /**
     * AADE answered, and the answer is no.
     *
     * Not retryable by construction — see the class docblock. A caller that
     * wants to try again after fixing the payload issues a **new** document
     * rather than re-sending this one.
     */
    public static function refused(
        string $errorCode,
        ?string $errorMessage = null,
        ?string $rawResponse = null,
    ): self {
        return new self(
            accepted: false,
            retryable: false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse,
        );
    }

    /**
     * Nobody answered. The payload may well be fine.
     *
     * `errorCode` is a transport shape (`timeout`, `http_502`) rather than an
     * AADE code, and the dictionary treats it as such: telling an operator that
     * error 502 means something about VAT categories would be worse than
     * telling them nothing.
     */
    public static function unreachable(string $errorCode, ?string $errorMessage = null): self
    {
        return new self(
            accepted: false,
            retryable: true,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    /**
     * The null gateway's answer: nothing was sent, and nothing pretends it was.
     *
     * Deliberately **not** accepted. An operator with no credentials must not
     * end up with a shelf of invoices marked as registered with a tax authority
     * that has never heard of them — that is a discovery for an audit, not for
     * a Tuesday. It is also not retryable: retrying will not conjure a
     * credential, and eight attempts would bury the one thing they need to read,
     * which is that myDATA is not switched on.
     */
    public static function notConfigured(): self
    {
        return new self(
            accepted: false,
            retryable: false,
            errorCode: 'not_configured',
        );
    }
}
