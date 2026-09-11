<?php

declare(strict_types=1);

namespace App\Domain\Import\Actions;

use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Jobs\AnalyseImportJob;
use App\Models\ImportJob;

/**
 * Opens an import from uploaded files and queues its dry run (SAA-13, SAA-14).
 *
 * The files stay on the private disk only as long as the import needs them:
 * {@see CommitImport} deletes them when the import completes, because they hold
 * every past customer's name and email and there is no reason to keep a second
 * copy of the operator's old shop.
 */
final class StartImport
{
    public function __invoke(string $disk, ?string $wxrPath, ?string $csvPath, ?int $userId): ImportJob
    {
        $job = ImportJob::query()->create([
            'source' => $wxrPath !== null ? ImportSource::Wxr : ImportSource::Csv,
            'status' => ImportStatus::Pending,
            'is_dry_run' => true,
            'connection' => [
                'disk' => $disk,
                'wxr_path' => $wxrPath,
                'csv_path' => $csvPath,
            ],
            'mapping' => [],
            'stats' => [],
            'created_by_user_id' => $userId,
        ]);

        AnalyseImportJob::dispatch((int) $job->getKey());

        return $job;
    }
}
