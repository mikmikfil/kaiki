<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Which date the export's window is measured on (spec OPS-17).
 *
 * ## The one decision that changes which rows are in the file
 *
 * OPS-17 asks for *"bookings CSV for accounting"* and stops there, which leaves
 * the most consequential question unanswered: a booking made in June for a trip
 * in August, paid in July, belongs to three different months depending on which
 * date you measure. Every one of the three is the right answer to somebody.
 *
 * Picking one silently is the version that fails, and it fails the way OPS-2
 * describes for the dashboard: an accountant reconciles the file against a bank
 * statement once, the totals disagree, and they conclude the product is wrong
 * about money. They are right to, and they do not tell anybody.
 *
 * So the basis is chosen on the form, defaulted per export type, recorded on
 * the row, and printed in the filename — a CSV cannot carry a comment line
 * without breaking the parsers that read it, and the filename is the only part
 * of the artefact that survives being forwarded as an attachment.
 */
enum ExportDateBasis: string
{
    use HasTranslatedLabel;

    /** When the booking was made — `bookings.created_at`. */
    case Booked = 'booked';

    /** When the boat leaves — `bookings.local_date`, the operator's own date. */
    case Departure = 'departure';

    /**
     * When money actually arrived — the earliest succeeded `payments.paid_at`.
     *
     * `paid_at`, never `created_at`, and #118 settled why: a payment row exists
     * from the moment the guest is redirected to the gateway, so a guest who
     * starts paying on Sunday night and finishes on Monday belongs to Monday.
     * The bank will say Monday too, and the bank is what this file is
     * reconciled against.
     */
    case Paid = 'paid';

    /**
     * The column the window is applied to.
     *
     * `local_date` for a departure, because an operator's week runs on their
     * own calendar rather than on UTC — a 02:00 sailing on the 1st is not the
     * previous month's trip, and it would be under a UTC comparison in Athens.
     */
    public function column(): string
    {
        return match ($this) {
            self::Booked => 'bookings.created_at',
            self::Departure => 'bookings.local_date',
            self::Paid => 'payments.paid_at',
        };
    }

    /** Does resolving this basis need the `payments` table? */
    public function needsPayments(): bool
    {
        return $this === self::Paid;
    }

    /**
     * Is this a whole-day column, so an inclusive `to_date` needs no time?
     *
     * `local_date` is a date. The other two are timestamps, where a naive
     * `<= '2026-09-30'` means midnight and silently drops everything that
     * happened on the last day of the window — the single most common
     * off-by-one in a reporting query, and one that looks like missing data
     * rather than like a bug.
     */
    public function isWholeDay(): bool
    {
        return $this === self::Departure;
    }
}
