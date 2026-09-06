<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Who cancelled (`docs/data-model.md` §2.5).
 *
 * It decides the refund. A guest cancellation is judged against the policy
 * snapshot's tiers; an operator or system cancellation — weather, too few
 * passengers — is refunded in full whatever the tiers say, because the guest
 * did nothing wrong.
 */
enum CancelledBy: string
{
    use HasTranslatedLabel;

    case Guest = 'guest';
    case Operator = 'operator';
    case System = 'system';

    /** Does the cancellation policy apply, or is this a full refund regardless? */
    public function policyApplies(): bool
    {
        return $this === self::Guest;
    }
}
