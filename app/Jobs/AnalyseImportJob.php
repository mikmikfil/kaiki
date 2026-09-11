<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Import\Actions\AnalyseImport;
use App\Enums\ImportStatus;
use App\Models\ImportJob;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The dry run, off the request (SAA-14).
 *
 * A season's export is a few megabytes of XML; reading it in the request that
 * uploaded it would hold the operator's browser for as long as that takes.
 * The review screen polls, so the operator watches the status move on its own.
 */
class AnalyseImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $importJobId) {}

    public function uniqueId(): string
    {
        return 'import-analyse:' . $this->importJobId;
    }

    public function handle(AnalyseImport $analyse): void
    {
        $job = Tenancy::withoutTenancy(fn (): ?ImportJob => ImportJob::query()->find($this->importJobId));

        if (! $job instanceof ImportJob || $job->status !== ImportStatus::Pending) {
            return;
        }

        $tenant = Tenancy::withoutTenancy(static fn (): ?Tenant => Tenant::query()->find($job->tenant_id));

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($analyse): void {
            $job = ImportJob::query()->findOrFail($this->importJobId);

            $analyse($job);
        });
    }
}
