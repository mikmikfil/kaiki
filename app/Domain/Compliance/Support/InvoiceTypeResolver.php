<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Support;

use App\Enums\InvoiceType;
use App\Models\Booking;

/**
 * ΑΛΠ or ΤΠΥ, decided from the booking and from nothing else
 * (spec MYD-3.1, MYD-3.5, ADR-0003 Option A).
 *
 * ## A pure function, on purpose
 *
 * ADR-0003: *"Type is derived from data, not chosen by the guest in checkout …
 * This is a pure function of booking data, unit-testable, and explainable in the
 * operator error feed."* All three clauses matter and the last one most: when an
 * operator asks why a customer who wanted an invoice got a receipt, the answer
 * has to be a sentence about their data rather than a shrug about a queue.
 *
 * So this takes a booking and returns a type. No database writes, no clock, no
 * network, no config. {@see self::explain()} returns the reason in the same
 * pass, because a decision an operator cannot see the reasoning behind is a
 * decision they will not trust the second time it surprises them.
 *
 * ## Both halves are required, and that is not pedantry
 *
 * A ΤΠΥ needs a **validated ΑΦΜ and a legal name**. A number without a name
 * gives AADE a counterparty it cannot record; a name without a number is a
 * private customer who typed their company into the wrong box. Either alone
 * produces an ΑΛΠ, which is a correct document, rather than a ΤΠΥ that is
 * refused days later and discovered by the operator rather than by us.
 *
 * ## An invalid number is not an error here
 *
 * MYD-3.5 is explicit: a failed ΑΦΜ *"produces an ΑΛΠ and an operator-visible
 * warning"*. The sale is never held up over it. This class produces the warning
 * as an explanation; putting it in front of the operator is the panel's job.
 */
final class InvoiceTypeResolver
{
    /** Why a booking got the type it got, for the operator's error feed. */
    public const REASON_NO_TAX_DETAILS = 'no_tax_details';

    public const REASON_INVALID_VAT_NUMBER = 'invalid_vat_number';

    public const REASON_MISSING_LEGAL_NAME = 'missing_legal_name';

    public const REASON_VALIDATED_TAX_DETAILS = 'validated_tax_details';

    /**
     * The document this booking should be issued as.
     *
     * Never returns {@see InvoiceType::Credit}: a credit note is raised against
     * an existing invoice by the refund path (MYD-13), not derived from a
     * booking. A resolver that could return it would let a refund be mistaken
     * for a sale.
     */
    public static function for(Booking $booking): InvoiceType
    {
        return self::explain($booking)['type'];
    }

    /**
     * The type, and the reason, in one pass.
     *
     * One method rather than two so the two can never disagree — a separate
     * `reasonFor()` would be a second copy of this ladder, and the copy would be
     * the one that went stale.
     *
     * @return array{type: InvoiceType, reason: string}
     */
    public static function explain(Booking $booking): array
    {
        $vatNumber = trim((string) $booking->guest_vat_number);
        $legalName = trim((string) $booking->guest_company_name);

        if ($vatNumber === '' && $legalName === '') {
            return ['type' => InvoiceType::Alp, 'reason' => self::REASON_NO_TAX_DETAILS];
        }

        // A name with no number: a private customer who filled in the wrong
        // box, or a company that stopped halfway. Either way there is nothing
        // to put in a counterparty block.
        if ($vatNumber === '') {
            return ['type' => InvoiceType::Alp, 'reason' => self::REASON_NO_TAX_DETAILS];
        }

        $country = strtoupper(trim((string) $booking->guest_country)) ?: VatNumber::GREECE;

        if (! VatNumber::isValid($vatNumber, $country)) {
            return ['type' => InvoiceType::Alp, 'reason' => self::REASON_INVALID_VAT_NUMBER];
        }

        if ($legalName === '') {
            return ['type' => InvoiceType::Alp, 'reason' => self::REASON_MISSING_LEGAL_NAME];
        }

        return ['type' => InvoiceType::Tpy, 'reason' => self::REASON_VALIDATED_TAX_DETAILS];
    }

    /**
     * Did this booking ask for an invoice and not get one?
     *
     * The question the operator's feed is built on. A booking with no tax
     * details at all is not a problem — it is the ordinary case, and putting it
     * in a warning list would bury the two rows a month that are real.
     */
    public static function needsAttention(Booking $booking): bool
    {
        return in_array(
            self::explain($booking)['reason'],
            [self::REASON_INVALID_VAT_NUMBER, self::REASON_MISSING_LEGAL_NAME],
            strict: true,
        );
    }
}
