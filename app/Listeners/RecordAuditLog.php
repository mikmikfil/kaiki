<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Audit\Data\AuditContextData;
use App\Events\Auditable;
use App\Jobs\RecordAuditLogJob;

/**
 * Every {@see Auditable} event becomes a row (ADR-0025, spec SEC-16).
 *
 * Registered against the **interface**, so implementing `Auditable` is the
 * whole of wiring an action into the trail — Laravel's dispatcher walks
 * `class_implements` when it resolves listeners. That is the ADR's argument for
 * events over instrumenting call sites: *"a new destructive action is audited
 * by firing the event it should have fired anyway."*
 *
 * ## Synchronous, and it is the writing that is queued
 *
 * This class does two things and neither of them touches the database: it reads
 * the ambient request context, and it dispatches {@see RecordAuditLogJob}.
 *
 * Making *this* the queued listener would have been the obvious shape and it is
 * wrong: a queued listener is constructed on the worker, so `Auth::id()` is
 * null and `Tenancy::current()` holds whatever the previous job left — the
 * second being a cross-tenant write. Capturing here and queueing the write
 * keeps the request off the critical path while reading the context in the only
 * place it exists.
 *
 * `afterCommit()` for a reason of its own: a vessel delete is a transaction, and
 * a worker that picked the job up before the commit landed would record a
 * deletion that then rolled back — a false row in the one table whose whole
 * value is that it is true.
 */
final class RecordAuditLog
{
    public function handle(Auditable $event): void
    {
        RecordAuditLogJob::dispatch(
            // Called here, while the subject is still in memory. `auditEntry()`
            // captures the label a deleted row will not be able to supply later.
            $event->auditEntry(),
            AuditContextData::capture(),
        )->afterCommit();
    }
}
