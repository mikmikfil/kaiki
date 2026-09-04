<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Why a departure was cancelled (`docs/data-model.md` §2.4).
 *
 * Not decoration: the reason decides the refund. `weather` follows the
 * policy's own weather percentage (CXL-6), `min_pax` and
 * `vessel_booked_privately` are the operator's decision rather than the
 * guest's, and a guest who did nothing wrong is refunded differently from one
 * who cancelled. Storing "cancelled" without the reason would make that
 * distinction a support conversation.
 */
enum DepartureCancelReason: string
{
    use HasTranslatedLabel;

    /** Called off for weather — the operator's decision, CXL-6's path. */
    case Weather = 'weather';

    /** The operator's own reason, whatever it was. */
    case Operator = 'operator';

    /** Not enough passengers to sail (§4.2). */
    case MinPax = 'min_pax';

    /** A private charter took the boat (brief §5.3), with no seats sold. */
    case VesselBookedPrivately = 'vessel_booked_privately';

    /** Did the operator choose this, rather than the weather or the numbers? */
    public function isOperatorChoice(): bool
    {
        return $this === self::Operator || $this === self::VesselBookedPrivately;
    }
}
