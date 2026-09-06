<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a payment is for (spec PAY-8, FIXED; ADR-0004 Option D).
 *
 * `deposit` and `balance` are two **independent checkout sessions**, months
 * apart if need be, and that is the decision ADR-0004 made rather than an
 * accident of naming: neither Viva nor Stripe supports card-on-file behind one
 * abstraction, so the balance is a second session minted on demand from
 * `/b/{manage_token}` and priced at the moment the guest opens the page. Two
 * gateway fees instead of one is the accepted cost.
 *
 * `refund` is a row of its own pointing back at the charge it reverses, never a
 * negative amount — every money column here is unsigned, and §1.4 puts the sign
 * in the meaning.
 */
enum PaymentKind: string
{
    use HasTranslatedLabel;

    case Full = 'full';
    case Deposit = 'deposit';
    case Balance = 'balance';
    case Refund = 'refund';

    /** Does this take money in rather than give it back? */
    public function isIncoming(): bool
    {
        return $this !== self::Refund;
    }
}
