<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Which gateway took the money (`docs/data-model.md` §2.5).
 *
 * ## Named `PaymentGatewayName`, not `PaymentGateway`
 *
 * PAY-2 fixes `App\Contracts\PaymentGateway` as the *interface* the two
 * implementations sit behind, and a `PaymentGateway` enum beside a
 * `PaymentGateway` interface is a collision waiting for the first person who
 * imports the wrong one — in a file about money.
 *
 * ## Two of these four never call anything
 *
 * `cash` and `bank_transfer` are how a manual booking is marked paid (BKG-33).
 * They create ordinary `Payment` rows and are excluded from gateway
 * reconciliation, because there is no gateway to reconcile them against — the
 * money arrived at a desk or a bank account.
 */
enum PaymentGatewayName: string
{
    use HasTranslatedLabel;

    case Viva = 'viva';
    case Stripe = 'stripe';
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';

    /** Is there a third party to call, and to reconcile against later? */
    public function isExternal(): bool
    {
        return $this === self::Viva || $this === self::Stripe;
    }

    /** The credential provider this gateway reads (#79). */
    public function provider(): ?IntegrationProvider
    {
        return match ($this) {
            self::Viva => IntegrationProvider::Viva,
            self::Stripe => IntegrationProvider::Stripe,
            self::Cash, self::BankTransfer => null,
        };
    }
}
