<?php

declare(strict_types=1);

namespace App\Domain\Import\Actions;

use App\Contracts\ImportSource;
use App\Domain\Import\Exceptions\ImportFileUnreadable;
use App\Domain\Import\Support\MappingDefaults;
use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Models\ImportJob;
use App\Models\ImportJobRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The dry run (SAA-14): read the files, record every source row, propose a
 * mapping, and stop for the operator.
 *
 * **Writes nothing but `import_jobs` and `import_job_rows`.** No product, band,
 * departure or booking exists until {@see CommitImport} runs, and it runs only
 * when the operator presses the button on the review screen.
 *
 * Re-running it on the same job is safe: rows are keyed by
 * `(job, type, source id)`, so the same files produce the same rows, and the
 * operator's saved mapping is kept — defaults fill only the gaps.
 */
final class AnalyseImport
{
    public function __construct(
        private readonly ImportSource $source,
        private readonly EvaluateImportRows $evaluate,
    ) {}

    public function __invoke(ImportJob $job): void
    {
        $job->forceFill(['status' => ImportStatus::Analysing, 'started_at' => Carbon::now()])->save();

        try {
            $count = 0;

            foreach ($this->source->records($job) as $record) {
                $row = ImportJobRow::query()->firstOrNew([
                    'import_job_id' => $job->getKey(),
                    'source_type' => $record->type,
                    'source_id' => $record->sourceId,
                ]);

                $row->source_payload = $record->payload;

                if (! $row->exists) {
                    $row->status = ImportRowStatus::Pending;
                    $row->messages = [];
                }

                $row->save();
                $count++;
            }

            $job->forceFill(['mapping' => MappingDefaults::for($job, $job->mapping)])->save();

            ($this->evaluate)($job);

            $job->appendLog((string) __('imports.log.analysed', ['count' => $count]));
            $job->forceFill([
                'status' => ImportStatus::MappingReview,
                'error_message' => null,
            ])->save();
        } catch (ImportFileUnreadable $e) {
            $this->fail($job, $e->key, $e);
        } catch (Throwable $e) {
            $this->fail($job, 'imports.errors.generic', $e);
        }
    }

    private function fail(ImportJob $job, string $key, Throwable $e): void
    {
        Log::error('import.analysis_failed', [
            'import_job_id' => $job->getKey(),
            'tenant_id' => $job->tenant_id,
            'reason' => $key,
            'exception' => $e->getMessage(),
        ]);

        $job->appendLog((string) __($key));
        $job->forceFill([
            'status' => ImportStatus::Failed,
            'error_message' => (string) __($key),
            'finished_at' => Carbon::now(),
        ])->save();
    }
}
