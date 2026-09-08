<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Models\ExportJob;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes the file behind an expired export (spec OPS-18, GDR-2).
 *
 * ## An expired link is not enough on its own
 *
 * OPS-18 asks for *"a download link that expires after 24 hours"*, and it would
 * be met, technically, by a check on the way in. The file would still be
 * sitting on the disk a year later — a bookings CSV carrying every guest's name,
 * email and phone number, in a bucket nobody thinks about, retained for no
 * stated purpose. That is the disclosure GDR-2's retention rules exist to
 * prevent, and it is invisible: no screen in the product would ever mention it.
 *
 * So expiry has two halves. {@see ExportJob::isDownloadable()} closes the link
 * on time whether or not this job has run, and this job removes the data.
 *
 * ## Cross-tenant, deliberately
 *
 * There is no current tenant in the scheduler, and one sweep should visit every
 * operator — the same shape as the webhook retry sweeper (§2.9), matching
 * `export_jobs_purge_idx`. The row is kept: an operator asking *"where did my
 * export go"* deserves "it expired on Tuesday" rather than silence, which is
 * why {@see ExportStatus::Expired} is a state rather than a deletion.
 */
class PurgeExpiredExportsJob implements ShouldQueue
{
    use Queueable;

    /** How many rows one sweep will handle. */
    private const BATCH = 500;

    public function handle(): void
    {
        Tenancy::withoutTenancy(function (): void {
            ExportJob::query()
                ->dueForPurge()
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get()
                ->each(function (ExportJob $export): void {
                    $this->purge($export);
                });
        });
    }

    private function purge(ExportJob $export): void
    {
        $disk = $export->disk;
        $path = $export->path;

        if (is_string($disk) && $disk !== '' && is_string($path) && $path !== '') {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable $e) {
                // A file that cannot be deleted must not stop the sweep, and
                // must not be marked expired either — leaving the row in
                // `ready` means the next sweep tries again, and the link is
                // already closed by `isDownloadable()` regardless.
                Log::warning('export.purge_failed', [
                    'export_job_id' => $export->getKey(),
                    'disk' => $disk,
                    'exception' => $e->getMessage(),
                ]);

                return;
            }
        }

        // The row survives, with its counts and its window intact. What it
        // loses is the pointer to a file that is no longer there.
        $export->forceFill([
            'status' => ExportStatus::Expired,
            'disk' => null,
            'path' => null,
        ])->save();
    }
}
