<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How an extra is charged (`docs/data-model.md` §2.3, spec CAT-12, PRC-9).
 *
 * The third case is the interesting one. `on_request` — «κατόπιν αιτήματος» —
 * carries **no price at all** and never enters a total: the guest asks for it,
 * the operator arranges it, and the money is settled outside the booking. A
 * helicopter transfer or a private chef is priced by a phone call, and forcing
 * a number into the column would put an invented figure on a booking
 * confirmation.
 *
 * That is why `price_cents` is nullable and why saving one with a price is
 * refused rather than ignored: a price that is stored but not charged is a
 * price somebody will eventually charge.
 */
enum ExtraPricing: string
{
    use HasTranslatedLabel;

    /** One charge for the whole booking, however many people. */
    case PerBooking = 'per_booking';

    /** Multiplied by the number of passengers. */
    case PerPerson = 'per_person';

    /** No price. Recorded on the booking as a to-do for the operator. */
    case OnRequest = 'on_request';

    /** Does this extra carry a price at all (CAT-12)? */
    public function hasPrice(): bool
    {
        return $this !== self::OnRequest;
    }

    /** Does the charge scale with the passenger count (PRC-9)? */
    public function scalesWithPax(): bool
    {
        return $this === self::PerPerson;
    }
}
