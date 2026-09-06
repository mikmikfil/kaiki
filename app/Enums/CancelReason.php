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
 *
 * ## `quote_declined` was in the spec and not in the list
 *
 * BKG-26: *"Declining transitions to `cancelled` with reason
 * `quote_declined`."* `docs/data-model.md` §2.5 enumerated seven reasons and
 * that was not among them, so #85 added it and reconciled the table. It is
 * exactly the distinction this enum exists for: a guest who was quoted €950 and
 * said no is a **pricing** signal, and folding it into `guest_request` would
 * make the one number a quote-mode operator most wants unaskable.
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

    /** The guest read the quote and said no (BKG-26, added by #85). */
    case QuoteDeclined = 'quote_declined';
}
