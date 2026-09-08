<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where one webhook delivery has got to (spec OPS-20, `docs/api.md` §8.4).
 *
 * ## `failed` and `abandoned` are different, and an operator needs both
 *
 * `failed` is *the attempts ran out* — eight tries over roughly twenty-four
 * hours, and the receiver never answered `2xx`. It is re-sendable by hand, and
 * the panel offers that, because the usual cause is somebody's server having a
 * bad day and the usual fix is pressing the button once it is back.
 *
 * `abandoned` is *we stopped trying on purpose* — the endpoint was disabled or
 * deleted while this delivery was still queued. Nothing failed; there is
 * nowhere to send it. Collapsing the two into one state would put a retry
 * button on rows that cannot be retried and would count a switched-off endpoint
 * as an outage in the failure feed.
 */
enum DeliveryStatus: string
{
    use HasTranslatedLabel;

    /** Queued, or waiting out a backoff. */
    case Pending = 'pending';

    /** A `2xx` inside ten seconds. */
    case Delivered = 'delivered';

    /** Eight attempts, no `2xx`. Re-sendable. */
    case Failed = 'failed';

    /** The endpoint went away before we got there. Not re-sendable. */
    case Abandoned = 'abandoned';

    /** Is this one still going to be tried again on its own? */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /** Does this row belong in OPS-21's failure feed, with a retry button? */
    public function needsAttention(): bool
    {
        return $this === self::Failed;
    }

    /**
     * The Filament badge colour.
     *
     * `abandoned` is grey rather than red for the reason the class docblock
     * gives: nothing went wrong, and colouring it as a failure teaches an
     * operator to read an ordinary row as a problem.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Delivered => 'success',
            self::Failed => 'danger',
            self::Abandoned => 'gray',
        };
    }
}
