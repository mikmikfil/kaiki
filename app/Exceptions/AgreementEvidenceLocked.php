<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\CharterAgreement;
use RuntimeException;

/**
 * Something tried to rewrite an accepted ναυλοσύμφωνο (`docs/data-model.md` §2.6).
 *
 * §2.6's sentence: *"Acceptance evidence (timestamp + IP + user agent + typed
 * name) is the legally interesting part and is never overwritten. Regenerating
 * after the guest accepted is forbidden by the application — you create a new
 * version instead."*
 *
 * Thrown rather than silently ignored, for the reason {@see AuditLogIsAppendOnly}
 * gives: a caller whose write was quietly dropped goes on believing it
 * succeeded, and an M6 generator that believed it had refreshed an agreement
 * would show an operator a document that does not match the row. The remedy is
 * in the message because the caller is code, not an operator —
 * {@see CharterAgreement::openVersionFor()} is one call and does the right
 * thing.
 */
final class AgreementEvidenceLocked extends RuntimeException
{
    /** @param list<string> $columns */
    public static function forColumns(CharterAgreement $agreement, array $columns): self
    {
        return new self(sprintf(
            'Charter agreement %s was accepted on %s and cannot be rewritten (%s). '
            . 'Use CharterAgreement::openVersionFor() — regeneration creates a new version (data-model §2.6).',
            self::describe($agreement),
            $agreement->guest_accepted_at?->toDateString() ?? 'an unrecorded date',
            implode(', ', $columns),
        ));
    }

    public static function forStatus(CharterAgreement $agreement): self
    {
        return new self(sprintf(
            'Charter agreement %s is accepted, and `accepted` has no outgoing transition. '
            . 'Voiding it would erase the guest\'s acceptance; raise a new version instead (data-model §2.6).',
            self::describe($agreement),
        ));
    }

    /**
     * The uuid and the version, never the snapshot.
     *
     * This message reaches a log. `fields_snapshot` holds both parties' names
     * and the vessel's registration, and §3.8 keeps all of it out of one.
     */
    private static function describe(CharterAgreement $agreement): string
    {
        return sprintf('%s [%s]', $agreement->uuid, $agreement->template_version);
    }
}
