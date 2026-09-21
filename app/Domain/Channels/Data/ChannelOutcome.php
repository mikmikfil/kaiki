<?php

declare(strict_types=1);

namespace App\Domain\Channels\Data;

/**
 * The four answers a channel can give (spec EXT-1, per ADR-0034).
 *
 * Deliberately **not** a translated enum: no operator ever reads these words.
 * {@see ChannelResult} carries the lang key for the sentence a person sees, and
 * this is the machine-readable half the retry ladder and the failure feed branch
 * on. {@see ChannelResult} explains why the four are not three.
 */
enum ChannelOutcome: string
{
    case Done = 'done';
    case NotApplicable = 'not_applicable';
    case NotConfigured = 'not_configured';
    case Failed = 'failed';
}
