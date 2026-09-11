<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Import\Actions\CommitImport;
use App\Enums\ImportStatus;
use App\Models\ImportJob;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The confirmed import, off the request (SAA-15).
 *
 * Unique per import, so a double click cannot start two runs writing the same
 * rows. Resumable by construction rather than by retry: {@see CommitImport}
 * skips every row already `imported`, so a run that died halfway — a worker
 * restarted, a deploy — is resumed from the review screen and picks up where
 * it stopped.
 */
class CommitImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One attempt; a stopped run is resumed by the operator, who can see why it stopped. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $importJobId) {}

    public function uniqueId(): string
    {
        return 'import-commit:' . $this->importJobId;
    }

    public function handle(CommitImport $commit): void
    {
        $job = Tenancy::withoutTenancy(fn (): ?ImportJob => ImportJob::query()->find($this->importJobId));

        if (! $job instanceof ImportJob || $job->status !== ImportStatus::Running) {
            return;
        }

        $tenant = Tenancy::withoutTenancy(static fn (): ?Tenant => Tenant::query()->find($job->tenant_id));

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($commit): void {
            $commit(ImportJob::query()->findOrFail($this->importJobId));
        });
    }
}
