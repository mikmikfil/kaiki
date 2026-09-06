<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What happened to an inbound webhook (`docs/data-model.md` §2.7).
 *
 * §2.7 lists four; this adds a fifth, `orphaned`, and the reason is PAY-7:
 *
 * > *A verified webhook for an unknown booking is stored and surfaced in the
 * > super-admin gateway error feed rather than discarded.*
 *
 * Under the four-value vocabulary that event is either `failed` — which is
 * wrong, nothing failed, the payload was fine and the signature was good — or
 * `ignored`, which is worse, because `ignored` means "we looked and decided it
 * did not concern us" and hides it from the feed it is supposed to appear in.
 *
 * An orphan is a real payment we cannot match to a booking. Somebody has been
 * charged. It needs a person, and it needs to be visibly different from the
 * duplicate deliveries and the event types we do not handle.
 */
enum WebhookEventStatus: string
{
    use HasTranslatedLabel;

    /** Written, not yet worked on. */
    case Received = 'received';

    case Processed = 'processed';

    /** An event type we do not act on, or a duplicate delivery. Nothing to see. */
    case Ignored = 'ignored';

    /** Something threw. Replayable, and in the failure feed. */
    case Failed = 'failed';

    /** Verified, genuine, and matched to no booking. Somebody has been charged. */
    case Orphaned = 'orphaned';

    /** Does this need a human to look at it (PAY-7)? */
    public function needsAttention(): bool
    {
        return $this === self::Failed || $this === self::Orphaned;
    }
}
