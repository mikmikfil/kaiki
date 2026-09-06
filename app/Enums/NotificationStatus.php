<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a send got to (`docs/data-model.md` §2.7, spec NTF-3, NTF-8).
 *
 * ## `sent` and `delivered` are not the same thing, and the difference is NTF-8
 *
 * `sent` means the provider accepted it. `delivered` means it arrived. Between
 * the two sits the case that matters: a **bounce**. A bounced confirmation is a
 * guest who will turn up at a quay expecting nothing, and NTF-8 requires the
 * booking to be flagged so the operator can telephone them — which is only
 * possible because the two states are kept apart.
 *
 * ## `failed` is ours and `bounced` is theirs
 *
 * `failed` is our side: the provider refused it, the credentials were wrong,
 * the number was unparseable. `bounced` is the recipient's: the mailbox does
 * not exist, or it rejected us. An operator can fix the first and can only
 * telephone about the second, so a status that merged them would be a feed
 * nobody could act on.
 */
enum NotificationStatus: string
{
    use HasTranslatedLabel;

    /** Written before the send, so a crash mid-flight leaves evidence. */
    case Queued = 'queued';

    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Failed = 'failed';

    /** Does this row belong in the operator's failure feed (BKG-14)? */
    public function needsAttention(): bool
    {
        return $this === self::Failed || $this === self::Bounced;
    }

    /** Did this attempt get as far as the provider accepting it? */
    public function reachedTheProvider(): bool
    {
        return $this === self::Sent || $this === self::Delivered;
    }
}
