<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The two exports OPS-17 asks for: bookings for accounting, and guests.
 *
 * ## The columns live here, and that is the point of the enum
 *
 * OPS-10's second half is one sentence — *"the standard bookings and guests CSV
 * exports exclude document numbers"* — and the tempting way to honour it is a
 * filter: build the row from the model, then remove the sensitive key. That
 * version is wrong in a way that only shows up later. A filter has to be
 * remembered by every future caller, and the failure mode of forgetting it is a
 * spreadsheet of passport numbers in an accountant's inbox, which nobody
 * notices because the file looks correct.
 *
 * So the columns are an allow-list. There is no mechanism here that could
 * include a column that is not written below, and
 * {@see ManifestColumn::DocumentNumber} — the only place in this
 * system that may carry one — is deliberately not reachable from this enum.
 * The test asserts the property rather than the implementation: it writes a
 * guest with a document number and asserts the bytes of the finished file do
 * not contain it.
 */
enum ExportType: string
{
    use HasTranslatedLabel;

    /**
     * One row per booking, with the money on it.
     *
     * "For accounting" in OPS-17 is what fixes the column list: an accountant
     * reconciles totals, VAT and what actually arrived, so the four money
     * columns are `total`, `paid`, `refunded` and `balance` rather than a
     * single "amount" that would need explaining every quarter.
     */
    case Bookings = 'bookings';

    /**
     * One row per person, for a head count and for age-band checking.
     *
     * Not a passenger manifest. A manifest is per departure, carries document
     * numbers, and is a logged action (OPS-8, OPS-10); this is the operator's
     * own list of who came, and the difference between the two is the whole
     * reason both exist.
     */
    case Guests = 'guests';

    /**
     * The columns this export writes, in order, as translation keys.
     *
     * The key is the column's name in `lang/{locale}/exports.php`, so a Greek
     * operator opening the file in Excel reads Greek headers — I18N-1 does not
     * stop at the screen, and a CSV is the artefact most likely to be forwarded
     * to somebody who never saw the panel.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Bookings => [
                'reference',
                'status',
                'booked_at',
                'departure_date',
                'departure_time',
                'product',
                'vessel',
                'guest_name',
                'guest_email',
                'guest_phone',
                'pax_total',
                'currency',
                'subtotal',
                'extras',
                'discount',
                'total',
                'vat_rate',
                'vat',
                'paid',
                'refunded',
                'balance',
                'source',
                'cancelled_at',
                'cancel_reason',
            ],

            // `document_number` and `document_type` are absent, not filtered.
            // See the class docblock.
            self::Guests => [
                'reference',
                'departure_date',
                'product',
                'vessel',
                'position',
                'full_name',
                'date_of_birth',
                'nationality',
                'age_band',
                'counts_toward_capacity',
                'checked_in_at',
                'no_show',
            ],
        };
    }

    /**
     * The date bases this export can be windowed on.
     *
     * A guest list has no money on it, so `paid` would offer an operator a
     * window they cannot interpret — the guests on bookings *paid* in a week is
     * not a question anybody asks, and offering it invites somebody to answer
     * the wrong one.
     *
     * @return list<ExportDateBasis>
     */
    public function dateBases(): array
    {
        return match ($this) {
            self::Bookings => [
                ExportDateBasis::Booked,
                ExportDateBasis::Departure,
                ExportDateBasis::Paid,
            ],
            self::Guests => [
                ExportDateBasis::Departure,
                ExportDateBasis::Booked,
            ],
        };
    }

    /** The basis chosen when the operator expresses no preference. */
    public function defaultDateBasis(): ExportDateBasis
    {
        return $this->dateBases()[0];
    }

    public function allowsDateBasis(ExportDateBasis $basis): bool
    {
        return in_array($basis, $this->dateBases(), strict: true);
    }
}
