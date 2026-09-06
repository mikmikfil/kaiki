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
 */
enum GuestDocumentType: string
{
    use HasTranslatedLabel;

    case Passport = 'passport';
    case IdCard = 'id_card';
    case Other = 'other';
}
