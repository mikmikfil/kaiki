<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Which gateway took the money (`docs/data-model.md` §2.5).
 *
 * ## Named `PaymentGatewayName`, not `PaymentGateway`
 *
 * PAY-2 fixes `App\Contracts\PaymentGateway` as the *interface* the
 * implementations sit behind, and a `PaymentGateway` enum beside a
 * `PaymentGateway` interface is a collision waiting for the first person who
 * imports the wrong one — in a file about money.
 *
 * ## Two of these three never call anything
 *
 * `cash` and `bank_transfer` are how a manual booking is marked paid (BKG-33).
 * They create ordinary `Payment` rows and are excluded from gateway
 * reconciliation, because there is no gateway to reconcile them against — the
 * money arrived at a desk or a bank account.
 *
 * ## Stripe was here, and the product owner removed it
 *
 * ADR-0004 named two gateways, and the interface exists so that a second one is
 * a class rather than a refactor. **That property is unchanged.** `viva` is the
 * only external gateway today because it is the only one Greek operators asked
 * for, not because the seam closed behind it.
 *
 * The string values are load-bearing — they are what `payments.gateway` stores —
 * so removing a case is a schema-visible change rather than a tidy-up. Nothing
 * had ever written `stripe`, which is the only reason this needed no data
 * migration.
 */
enum PaymentGatewayName: string
{
    use HasTranslatedLabel;

    case Viva = 'viva';
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';

    /** Is there a third party to call, and to reconcile against later? */
    public function isExternal(): bool
    {
        return $this === self::Viva;
    }

    /** The credential provider this gateway reads (#79). */
    public function provider(): ?IntegrationProvider
    {
        return match ($this) {
            self::Viva => IntegrationProvider::Viva,
            self::Cash, self::BankTransfer => null,
        };
    }
}
