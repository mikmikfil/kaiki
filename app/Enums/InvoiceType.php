<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Compliance\Support\InvoiceTypeResolver;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Which document AADE is being sent (spec MYD-2, MYD-3, `docs/data-model.md`
 * §4.6 `invoices.type`, ADR-0003 Option A).
 *
 * ## Latin keys, Greek labels
 *
 * The values are `alp`, `tpy` and `credit` rather than «ΑΛΠ» and «ΤΠΥ». A Greek
 * enum value inside a `varchar` is a portability and tooling hazard — it travels
 * through URLs, log lines, CSV headers and a MySQL collation, any one of which
 * can mangle it — so the Greek lives in the lang files where an operator reads
 * it and nothing else depends on the bytes. `docs/data-model.md` fixes this.
 *
 * ## Nobody chooses this in a checkout
 *
 * ADR-0003 is explicit: **the type is derived from booking data, not offered as
 * a menu.** A guest who is asked "receipt or invoice?" while trying to pay is a
 * guest who abandons a booking over a question they cannot answer, and half of
 * those who do answer choose wrong. {@see InvoiceTypeResolver}
 * is the one place that decides, from a validated ΑΦΜ plus a legal name.
 */
enum InvoiceType: string
{
    use HasTranslatedLabel;

    /**
     * Απόδειξη λιανικής πώλησης — the retail receipt, and the default.
     *
     * What a private person buying a boat trip gets, which is nearly every
     * booking this product will ever see.
     */
    case Alp = 'alp';

    /**
     * Τιμολόγιο παροχής υπηρεσιών — the services invoice.
     *
     * Issued only when the booking carries a **validated** ΑΦΜ and a legal name.
     * An unvalidated number produces an ΑΛΠ and a warning rather than a ΤΠΥ that
     * AADE will reject (MYD-3.5): a rejected invoice is an operator's problem
     * days later, and a receipt is correct today.
     */
    case Tpy = 'tpy';

    /**
     * The cancellation document, raised against an earlier one.
     *
     * Never issued on its own — `invoices.cancels_invoice_id` always points at
     * the document being undone (MYD-13). A refund path that produced one
     * without that link would leave an operator's series with a credit note
     * nobody can trace to a sale.
     */
    case Credit = 'credit';

    /** Is this document undoing another one? */
    public function isCredit(): bool
    {
        return $this === self::Credit;
    }

    /**
     * The short form printed on the document and read down the telephone.
     *
     * Greek in both locales, because «ΑΛΠ» is what the paper says and what an
     * accountant asks for; translating it to "retail receipt" on an English
     * panel would leave an operator unable to match the screen to the document.
     */
    public function code(): string
    {
        return match ($this) {
            self::Alp => 'ΑΛΠ',
            self::Tpy => 'ΤΠΥ',
            self::Credit => 'ΠΙΣ',
        };
    }
}
