<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Why a booking was cancelled (`docs/data-model.md` §2.5).
 *
 * A fixed list rather than free text, because these are counted: how many
 * trips did the weather cost this season, and how many holds expired at
 * checkout. The second is a conversion problem and the first is not, and a
 * free-text column could not tell them apart.
 */
enum CancelReason: string
{
    use HasTranslatedLabel;

    case GuestRequest = 'guest_request';
    case Weather = 'weather';
    case Operator = 'operator';
    case MinPax = 'min_pax';
    case VesselBookedPrivately = 'vessel_booked_privately';
    case PaymentFailed = 'payment_failed';
    case HoldExpired = 'hold_expired';
}
