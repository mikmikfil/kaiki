<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Whether a voucher can still be spent (`docs/data-model.md` §2.5).
 *
 * `redeemed` means fully consumed — `remaining_cents` reached zero — and is
 * distinct from `cancelled`, which the operator did on purpose. An operator
 * looking at a voucher that a guest says did not work needs to be told which of
 * the two happened.
 */
enum VoucherStatus: string
{
    use HasTranslatedLabel;

    case Active = 'active';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** Only an active voucher may be applied to a booking. */
    public function isSpendable(): bool
    {
        return $this === self::Active;
    }
}
