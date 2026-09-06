<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Events\BookingRefunded;

/**
 * What an audit row records (ADR-0025 §2, spec SEC-16).
 *
 * ## A short list, and the shortness is the decision
 *
 * ADR-0025 rejected the complete-history option explicitly: *"most rows would
 * never be read, the table would grow without bound, and — decisively — every
 * extra row is another row naming a person that the retention and erasure story
 * has to account for."*
 *
 * So this enum is **SEC-16's five named actions, plus every soft delete and
 * every operator override that carries a reason**, and adding a case to it is
 * a decision about privacy surface rather than a convenience. An ordinary field
 * edit is not here and must not be added: `AuditScopeTest` asserts a plain save
 * writes nothing.
 *
 * ## Two of the five are not buildable yet
 *
 * `BookingRefunded` and `GdprPurged` are on SEC-16's list and their subjects do
 * not exist until M2 and M6. They are cases here anyway, so that the milestone
 * that builds them fires an existing action rather than inventing a spelling —
 * the same reasoning that keeps `maintenance` in
 * {@see WindowUnavailableReason}. `AuditActionCoverageTest` records which are
 * live, so the gap stays visible rather than becoming folklore.
 */
enum AuditAction: string
{
    use HasTranslatedLabel;

    /** SEC-16: delete vessel. */
    case VesselDeleted = 'vessel.deleted';

    /** SEC-16: delete product. */
    case ProductDeleted = 'product.deleted';

    /** SEC-16: cancel departure (AVL-28, CXL-5). */
    case DepartureCancelled = 'departure.cancelled';

    /** SEC-16: refund. M2 — the subject does not exist yet. */
    case BookingRefunded = 'booking.refunded';

    /** SEC-16: purge. M6, with the GDPR request that orders it (ADR-0012). */
    case GdprPurged = 'gdpr.purged';

    /**
     * What started all of this (#10).
     *
     * A revoked key is the incident an operator most needs to see for
     * themselves, and it was satisfied by a `Log::info` that only a Hetzner
     * shell could read.
     */
    case ApiKeyRevoked = 'api_key.revoked';

    /**
     * Any other soft delete (ADR-0025 §2).
     *
     * One case rather than one per model, because the interesting fact is
     * *what* was deleted and that is already in `subject_type` — a
     * `cancellation_policy.deleted` case would be the same row with the
     * information written twice, and a new one for every table M2 adds.
     *
     * The three named deletions above keep their own cases because they are
     * named in SEC-16 and a reader scanning the trail for them should not have
     * to know they are spelled generically.
     */
    case RecordDeleted = 'record.deleted';

    /**
     * An operator overriding a rule, with the reason they gave (CXL-5).
     *
     * The reason is the point: "storm" and "not enough bookings" are different
     * events with different consequences, and SEC-16 asks for one *where
     * applicable*. This is where it applies.
     */
    case OverrideApplied = 'override.applied';

    /**
     * The actions whose subjects exist and that are wired today.
     *
     * `booking.refunded` left this list in #84, which is the milestone that
     * built `bookings` and the refund path — ADR-0025's own note said the gap
     * was recorded *"so the milestone which builds them fires an existing
     * action rather than inventing a spelling"*, and that is what
     * {@see BookingRefunded} does.
     *
     * `gdpr.purged` stays: ADR-0012's purge job is M6.
     */
    public function isLive(): bool
    {
        return match ($this) {
            self::GdprPurged => false,
            default => true,
        };
    }
}
