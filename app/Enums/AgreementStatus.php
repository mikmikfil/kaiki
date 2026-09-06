<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Models\CharterAgreement;

/**
 * Where a ναυλοσύμφωνο stands (`docs/data-model.md` §2.6).
 *
 * ## `accepted` is a one-way door
 *
 * Every other status here describes a document in progress. `accepted` records
 * something a **guest** did, at a timestamp, from an IP, with a typed name —
 * and §2.6 calls that *"the legally interesting part"*. So it has no outgoing
 * transitions at all: not to `void`, not back to `generated`, not anywhere.
 *
 * An operator who needs a different agreement gets a **new version**, which is
 * a new row under the same booking (`charter_agr_tenant_booking_uq` keys on
 * `template_version`). The old row and its evidence stay exactly as they were.
 * {@see CharterAgreement} enforces this; the enum states it.
 *
 * ## `void` exists for the row nobody accepted
 *
 * A draft raised against the wrong booking, or an agreement superseded before
 * the guest ever opened it. §2.6: *"the old one goes `void` only by explicit
 * operator action"* — never by a job, and never as a side effect of generating
 * the next version.
 *
 * The document generation itself is **M6**; this enum lands with the table in
 * #88 because the table lands early (SQLite and foreign keys, §0).
 */
enum AgreementStatus: string
{
    use HasTranslatedLabel;

    /** A row exists; nothing has been rendered. */
    case Draft = 'draft';

    /** The PDF has been produced and hashed. */
    case Generated = 'generated';

    /** Emailed to both parties, awaiting the guest. */
    case Sent = 'sent';

    /** The guest accepted, and the evidence is now frozen. */
    case Accepted = 'accepted';

    /** Withdrawn by an operator, before acceptance. */
    case Void = 'void';

    /**
     * Is the evidence on this row frozen?
     *
     * The single question the write guard asks. It is a method rather than a
     * comparison at each call site because "which statuses are immutable" is
     * exactly the kind of thing that acquires a second answer when it is
     * written out twice.
     */
    public function isEvidenceLocked(): bool
    {
        return $this === self::Accepted;
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Generated, self::Void],
            self::Generated => [self::Sent, self::Accepted, self::Void],
            self::Sent => [self::Accepted, self::Void],
            // See the class docblock: nothing leaves `accepted`.
            self::Accepted, self::Void => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
