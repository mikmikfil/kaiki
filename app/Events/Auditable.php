<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\Data\AuditEntryData;
use App\Listeners\RecordAuditLog;

/**
 * An event that leaves a row in the operator's audit trail (ADR-0025).
 *
 * ## An interface, listened to as one
 *
 * `RecordAuditLog` is registered against **this interface**, not against each
 * event — Laravel's dispatcher walks `class_implements` when it resolves
 * listeners, so implementing this is the whole of wiring an action into the
 * trail. That is the ADR's own argument for events over instrumenting call
 * sites: *"a new destructive action is audited by firing the event it should
 * have fired anyway."*
 *
 * The alternative — a listener registration per event — is a list that has to
 * be edited in a second file every time, which is the list somebody eventually
 * forgets. `AuditScopeTest` asserts the coverage from the enum's side, so a
 * live action with no event is a red test rather than a silent gap.
 *
 * ## The event carries scalars, because the listener is queued
 *
 * By the time the job runs the subject is usually deleted. See
 * {@see AuditEntryData} for why the label is captured at fire time.
 */
interface Auditable
{
    /** What this event wants written to the trail. */
    public function auditEntry(): AuditEntryData;
}
