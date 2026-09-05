<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Data\AuditEntryData;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one row to the operator's audit trail (ADR-0025, spec SEC-16).
 *
 * The only writer. `AuditLog::create()` anywhere else would be a second place
 * that decides what a row looks like, and the two would disagree first about
 * `ip_address` and then about whether a missing tenant is an error.
 *
 * ## A failure here never fails the action that caused it
 *
 * The issue's own words: *"a vessel delete that succeeded must not appear to
 * have failed because the trail was unavailable."* An operator who deletes a
 * boat, sees an error, and deletes it again is worse off than one whose trail
 * has a gap — and the gap is loud, because the failure is logged with the
 * entry it could not write.
 *
 * This is why the listener is queued as well: the swallow here covers a
 * database that rejected the insert, and the queue covers a database that was
 * not there at all.
 *
 * ## The tenant is explicit, not resolved
 *
 * The listener runs on a **worker**, where `Tenancy::current()` is whatever the
 * last job left behind — and writing one operator's audit row against another
 * operator's tenant is the precise failure ADR-0001 and #8's isolation gate
 * exist to prevent. So the tenant travels with the job and is applied here,
 * rather than being read from ambient state that a queue does not have.
 */
final class RecordAuditEntry
{
    public function __invoke(
        AuditEntryData $entry,
        Tenant $tenant,
        ?int $userId = null,
        ?string $ipAddress = null,
    ): ?AuditLog {
        try {
            return Tenancy::forTenant($tenant, static fn (): AuditLog => AuditLog::create([
                'user_id' => $userId,
                'action' => $entry->action,
                'subject_type' => $entry->subjectType,
                'subject_id' => $entry->subjectId,
                'subject_label' => $entry->subjectLabel,
                'reason' => $entry->reason,
                'context' => $entry->context,
                'ip_address' => $ipAddress,
            ]));
        } catch (Throwable $e) {
            // Logged with the entry it failed to write, so the gap in the trail
            // is recoverable by hand rather than merely noticed. The exception
            // message is logged and never surfaced: CNV-11 keeps a class name
            // and a SQL fragment off every screen, and this one has already
            // decided not to reach a screen at all.
            Log::error('Failed to write an audit log entry.', [
                'tenant_id' => $tenant->getKey(),
                'action' => $entry->action->value,
                'subject_type' => $entry->subjectType,
                'subject_id' => $entry->subjectId,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
