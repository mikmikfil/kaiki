<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Audit\Actions\RecordAuditEntry;
use App\Domain\Audit\Data\AuditContextData;
use App\Domain\Audit\Data\AuditEntryData;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes one audit row, off the request (ADR-0025, spec SEC-16).
 *
 * ## Why the queued half is a job and not the listener
 *
 * A queued **listener** is constructed on the worker, so anything it reads from
 * ambient state — the authenticated user, the resolved tenant — is read in the
 * wrong place. This job is handed both as plain data by a synchronous listener
 * that still has a request around it. See {@see AuditContextData}.
 *
 * It also carries **no models**. Half these rows describe a deletion, so by the
 * time the worker runs there is frequently nothing left to re-fetch — a
 * `SerializesModels` payload would arrive as a `ModelNotFoundException` for
 * exactly the events that matter most.
 *
 * ## A failure here never reaches the operator
 *
 * The issue: *"a vessel delete that succeeded must not appear to have failed
 * because the trail was unavailable."* The dispatch returns before anything is
 * written, so the delete has already succeeded; `RecordAuditEntry` logs a
 * refused insert rather than throwing; and `$tries` bounds the retrying.
 */
final class RecordAuditLogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Three attempts, then give up quietly.
     *
     * The trail matters and it does not matter more than the queue: a row that
     * will not write after three tries is one a human has to know about, which
     * `RecordAuditEntry` has already logged with everything needed to replay it.
     */
    public int $tries = 3;

    public function __construct(
        private readonly AuditEntryData $entry,
        private readonly AuditContextData $context,
    ) {}

    public function handle(RecordAuditEntry $record): void
    {
        $tenant = $this->context->tenant();

        // No tenant, no trail. It happens in a console command run outside any
        // operator's context, and it is a drop rather than an error — writing
        // the row against an arbitrary tenant would be far worse than not
        // writing it.
        if (! $tenant instanceof Tenant) {
            return;
        }

        $record($this->entry, $tenant, $this->context->userId, $this->context->ipAddress);
    }
}
