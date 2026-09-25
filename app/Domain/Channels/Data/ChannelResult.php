<?php

declare(strict_types=1);

namespace App\Domain\Channels\Data;

use App\Domain\Availability\Support\IcalSyncResult;
use App\Domain\Compliance\Support\AadeErrors;
use App\Domain\Integrations\Data\VerificationResult;

/**
 * What one call to a channel concluded (spec EXT-1, per ADR-0034).
 *
 * ## Four outcomes, because three of them look like silence
 *
 * The same shape {@see IcalSyncResult} exists for, one level up. A caller has to
 * be able to tell apart:
 *
 * - **done** — the channel was told, and agreed.
 * - **notApplicable** — this channel does not do this, and never will. An iCal
 *   feed is fetched *from* us; there is nothing to push to it. This is not a
 *   failure and must never reach the failure feed, or every operator with a
 *   calendar would collect a daily error that means nothing.
 * - **notConfigured** — this channel could do it, but this operator has not
 *   connected it, or the platform has it switched off. Nothing was sent.
 * - **failed** — we tried and it did not work. Only this one is retryable, and
 *   only this one is a failure an operator should ever see.
 *
 * Collapsing *notApplicable* into *done* is the tempting simplification and the
 * wrong one: a push that silently did nothing would report success to a
 * reconciliation job, which would then conclude the far side is up to date.
 *
 * ## The message is a lang key, never a sentence
 *
 * CNV-11, the same rule {@see VerificationResult} follows: the channel returns
 * the key and its arguments, and whoever renders
 * translates at the moment the reader's locale is known. `providerDetail` is
 * the far side's own words, kept for a support ticket and never shown alone.
 */
final class ChannelResult
{
    /**
     * @param  array<string, string|int>  $messageArguments  replacements for the lang line
     */
    private function __construct(
        public readonly ChannelOutcome $outcome,
        public readonly ?string $messageKey = null,
        public readonly array $messageArguments = [],
        public readonly ?string $providerDetail = null,
        public readonly int $affected = 0,
    ) {}

    /** The channel was told, and agreed. `$affected` counts rows or items moved. */
    public static function done(int $affected = 0): self
    {
        return new self(outcome: ChannelOutcome::Done, affected: $affected);
    }

    /**
     * This channel does not do this, by its nature rather than by its state.
     *
     * Takes a reason so a reader of a log is not left guessing which of the
     * interface's methods a given channel declines and why.
     */
    public static function notApplicable(string $because): self
    {
        return new self(outcome: ChannelOutcome::NotApplicable, providerDetail: $because);
    }

    /** Connected it is not — no credential, or the platform switch is off. */
    public static function notConfigured(): self
    {
        return new self(
            outcome: ChannelOutcome::NotConfigured,
            messageKey: 'channels.result.not_configured',
        );
    }

    /**
     * @param  array<string, string|int>  $messageArguments
     */
    public static function failed(
        string $messageKey,
        array $messageArguments = [],
        ?string $providerDetail = null,
    ): self {
        return new self(
            outcome: ChannelOutcome::Failed,
            messageKey: $messageKey,
            messageArguments: $messageArguments,
            providerDetail: $providerDetail,
        );
    }

    public function succeeded(): bool
    {
        return $this->outcome === ChannelOutcome::Done;
    }

    /**
     * Should a caller try this again later?
     *
     * Only an outright failure. *notConfigured* will not fix itself on a retry —
     * a credential does not appear because we asked twice — and *notApplicable*
     * is permanent by definition. This is the same distinction
     * {@see AadeErrors::isRetryable()} draws, and for the same reason: a retry
     * ladder is for outages, not for answers.
     */
    public function isRetryable(): bool
    {
        return $this->outcome === ChannelOutcome::Failed;
    }

    /** Is this something an operator should be shown in the failure feed (OPS-21)? */
    public function needsAttention(): bool
    {
        return $this->outcome === ChannelOutcome::Failed
            || $this->outcome === ChannelOutcome::NotConfigured;
    }
}
