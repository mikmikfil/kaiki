<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Why a boat is unavailable (`docs/data-model.md` §2.4).
 *
 * Not decoration: the reason decides who may remove the block. A
 * `private_booking` block belongs to a booking and disappears when that booking
 * is cancelled — deleting it by hand would free a boat somebody has paid for.
 * An `external_ical` block belongs to a feed and comes back on the next poll,
 * so removing it in the panel is a promise the sync will break.
 *
 * Only `maintenance` and `manual` are the operator's to delete, which is what
 * {@see self::isOperatorOwned()} answers.
 */
enum BlockReason: string
{
    use HasTranslatedLabel;

    /** Created by a whole-boat booking, in the same transaction (AVL-35). */
    case PrivateBooking = 'private_booking';

    /** The boat is out of the water, or the engine is in pieces. */
    case Maintenance = 'maintenance';

    /** Imported from an external calendar; re-created on the next poll. */
    case ExternalIcal = 'external_ical';

    /** Anything else the operator wants to keep the boat free for. */
    case Manual = 'manual';

    /** May an operator delete this block directly? */
    public function isOperatorOwned(): bool
    {
        return $this === self::Maintenance || $this === self::Manual;
    }

    /** Does something outside the panel own this block's lifecycle? */
    public function isSynced(): bool
    {
        return $this === self::ExternalIcal;
    }
}
