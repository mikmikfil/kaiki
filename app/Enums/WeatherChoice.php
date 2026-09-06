<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a guest wants after the weather cancelled their trip (spec CXL-6, CXL-7).
 *
 * ## Three options, and two of them move the same money
 *
 * CXL-6 offers *"refund, voucher, or a rebook link"*. The first is cash back
 * through the gateway; the second is credit valid for
 * `force_majeure_voucher_months` (CXL-8).
 *
 * **`Rebook` issues the same voucher as `Voucher`**, and that is a decision
 * rather than an oversight. There is no seat-transfer flow in the product — a
 * guest rebooking makes a *new* booking, and the only mechanism that carries
 * their money to it is a voucher. What `rebook` adds is intent: the operator's
 * list can tell "this guest is coming back" from "this guest took the credit
 * and may not", and the email carries a link to the product rather than a
 * balance. Collapsing the two into one case would throw that away; issuing
 * nothing for a rebook would strand the money, which is precisely what CXL-7
 * exists to prevent.
 *
 * ## The default is `refund`, and the reason is asymmetry
 *
 * CXL-7's operator default is per tenant, and the platform's fallback is
 * `refund` — the only one of the three that cannot leave a guest holding
 * credit they never asked for and may never spend.
 */
enum WeatherChoice: string
{
    use HasTranslatedLabel;

    /** Cash back through the gateway that took it. */
    case Refund = 'refund';

    /** Credit, valid for `force_majeure_voucher_months` from issue (CXL-8). */
    case Voucher = 'voucher';

    /** Credit plus the intent to come back — see the class docblock. */
    case Rebook = 'rebook';

    /** Does honouring this choice issue a voucher rather than move cash? */
    public function issuesVoucher(): bool
    {
        return $this === self::Voucher || $this === self::Rebook;
    }

    /**
     * The platform fallback when a tenant has set none.
     *
     * Config would be the other home for this, and `kaiki.booking` does carry
     * it — but a null column needs an answer at the point of use, and a `??`
     * chain that ends in a string literal is the thing `NoHardcodedStringsTest`
     * is right to object to.
     */
    public static function platformDefault(): self
    {
        return self::Refund;
    }
}
