<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What kind of identity document a guest supplied (`docs/data-model.md` §2.5).
 *
 * Plaintext, deliberately: the *type* is not sensitive and the manifest has to
 * be able to group by it. `document_number` beside it is encrypted, never
 * indexed, and purged after the retention window (ADR-0012).
 *
 * Named `GuestDocumentType` rather than `DocumentType` because M6 brings
 * ναυλοσύμφωνα and invoices, and "document" there means something else
 * entirely.
 *
 * ## Two kinds, and nothing else (2026-09-17)
 *
 * A passport or an identity card: those are the documents the port authority
 * accepts on a passenger list, so «Άλλο» went. The rows that had said `other`
 * became `id_card` in the migration that added «Χωρίς έγγραφο» to age bands.
 * An identity card is its number alone; a passport also has an expiry date.
 */
enum GuestDocumentType: string
{
    use HasTranslatedLabel;

    case Passport = 'passport';
    case IdCard = 'id_card';

    /** Does the manifest need this document's expiry date? */
    public function needsExpiry(): bool
    {
        return $this === self::Passport;
    }
}
