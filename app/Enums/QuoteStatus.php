<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a quote stands (`docs/data-model.md` §4.4).
 *
 * ## `expired` means two different things, deliberately
 *
 * A quote that ran past `valid_until`, and a quote that was **superseded** by a
 * newer version. §4.4 gives both the same terminal state, and the reason is the
 * guest: either way the link they are holding is no longer the offer, and
 * `/q/{token}` renders that rather than a dead end. `expired_at` records when,
 * and the presence of a newer `version` on the same booking says which.
 *
 * ## There is no `revised`
 *
 * §4.4's diagram draws `sent → draft: revise`, and its own transition table
 * then says the row *"never returns to `draft` in place — the diagram edge is
 * the operator-visible action, the implementation is create-and-supersede, so
 * the audit trail is intact."* A `revised` case would make the two readings
 * disagree; there is one row per version and each keeps its own final status.
 */
enum QuoteStatus: string
{
    use HasTranslatedLabel;

    /** Being built in the panel. The guest has seen nothing. */
    case Draft = 'draft';

    /** Emailed, with a live `/q/{token}` link. */
    case Sent = 'sent';

    case Accepted = 'accepted';
    case Declined = 'declined';

    /** Ran out, or was replaced. See the class docblock. */
    case Expired = 'expired';

    /** Is this the end of the line for this row? */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Accepted, self::Declined, self::Expired => true,
            default => false,
        };
    }

    /** Can a guest still act on it? */
    public function isOpen(): bool
    {
        return $this === self::Sent;
    }

    /**
     * The §4.4 transition table, in one place.
     *
     * On the enum rather than in the Actions, for the reason `BookingStatus`
     * gives: a transition table spread across five Actions is a table nobody
     * can read.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Expired],
            self::Sent => [self::Accepted, self::Declined, self::Expired],
            self::Accepted, self::Declined, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
