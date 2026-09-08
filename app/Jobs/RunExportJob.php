<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Operations\Support\CsvWriter;
use App\Domain\Operations\Support\ExportRows;
use App\Enums\ExportStatus;
use App\Models\ExportJob;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds one CSV and puts it where the download route can find it
 * (spec OPS-18, NFR-8).
 *
 * ## Written to a temporary stream, then moved in one call
 *
 * The obvious implementation writes rows straight to the storage disk. On the
 * local driver that works; on S3 every `append` is a read-modify-write of the
 * whole object, so a hundred thousand rows is a hundred thousand uploads of an
 * ever-growing file. So the rows go to `php://temp` — which spills to disk of
 * its own accord past a few megabytes, never holding the file in memory — and
 * the finished handle is streamed to the disk once.
 *
 * That also makes failure clean. A job that dies halfway leaves nothing behind
 * on the disk at all, rather than a truncated CSV that looks like a complete
 * one to whoever opens it.
 *
 * ## `expires_at` is stamped on completion (OPS-18)
 *
 * Twenty-four hours from the moment the operator asked would become twenty-one
 * for an export that waited behind a catalogue import, and the operator would
 * have no way to see why their link died early.
 *
 * ## A failure is a row an operator can read, in Greek
 *
 * NFR-8. The exception text is structured-logged for us; what the operator gets
 * is a translated sentence and a button to try again. A stack trace on an
 * operator's screen is not an error message, and a silent `failed` with no
 * reason is worse.
 */
class RunExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Two attempts.
     *
     * One retry covers the ordinary transient — a disk that was briefly
     * unreachable. Beyond that an export fails for a reason a third attempt
     * will not fix, and the operator can ask again in one click, which is a
     * cheaper recovery than a queue that keeps grinding on a broken query.
     */
    public int $tries = 2;

    public function __construct(public readonly int $exportJobId) {}

    /** One run per row, so a double dispatch cannot write the file twice. */
    public function uniqueId(): string
    {
        return 'export:' . $this->exportJobId;
    }

    public function handle(): void
    {
        $export = Tenancy::withoutTenancy(
            fn (): ?ExportJob => ExportJob::query()->find($this->exportJobId),
        );

        // Deleted between dispatch and execution, or the tenant went with it.
        // Nothing to report to and nothing to do.
        if (! $export instanceof ExportJob) {
            return;
        }

        // A retry of a job whose first attempt finished. Rebuilding would
        // replace a file somebody may already hold a link to.
        if ($export->status !== ExportStatus::Queued) {
            return;
        }

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($export->tenant_id),
        );

        if (! $tenant instanceof Tenant) {
            $this->fail($export, 'exports.errors.tenant_missing', null);

            return;
        }

        // Inside the tenant, because the row builder reads translatable titles,
        // the tenant's timezone and its currency — all of which are wrong or
        // missing without it.
        Tenancy::forTenant($tenant, function () use ($export): void {
            $this->build($export);
        });
    }

    private function build(ExportJob $export): void
    {
        $export->forceFill([
            'status' => ExportStatus::Processing,
            'started_at' => Carbon::now(),
        ])->save();

        $handle = fopen('php://temp/maxmemory:' . self::spillBytes(), 'r+');

        if ($handle === false) {
            $this->fail($export, 'exports.errors.temporary_file', null);

            return;
        }

        try {
            $rows = new ExportRows($export);

            $writer = CsvWriter::to($handle);
            $writer->write($rows->header());

            $count = $rows->stream(static function (array $row) use ($writer): void {
                $writer->write($row);
            });

            $size = ftell($handle);
            rewind($handle);

            $disk = self::disk();
            $filename = self::filename($export);
            $path = self::directory($export) . '/' . $export->uuid . '.csv';

            Storage::disk($disk)->writeStream($path, $handle);

            $export->forceFill([
                'status' => ExportStatus::Ready,
                'disk' => $disk,
                'path' => $path,
                'filename' => $filename,
                'row_count' => $count,
                'byte_size' => $size === false ? 0 : $size,
                'completed_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addHours(self::ttlHours()),
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            $this->fail($export, 'exports.errors.generic', $e);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Mark the row failed, with a sentence the operator can read.
     *
     * `forceFill` and `save` rather than an update through the action, because
     * this runs after something already went wrong and must not depend on
     * anything else working.
     */
    private function fail(ExportJob $export, string $key, ?Throwable $e): void
    {
        Log::error('export.failed', [
            'export_job_id' => $export->getKey(),
            'tenant_id' => $export->tenant_id,
            'type' => $export->type->value,
            'reason' => $key,
            'exception' => $e?->getMessage(),
        ]);

        $export->forceFill([
            'status' => ExportStatus::Failed,
            // Translated when written, into the tenant's own language: the
            // operator who reads this row may not be the one who asked, and the
            // row outlives the request that produced it.
            'error' => (string) __($key),
            'completed_at' => Carbon::now(),
        ])->save();
    }

    /**
     * A filename an operator can find again in a downloads folder in November.
     *
     * The date basis is in it deliberately. It is the one thing that decides
     * which rows are in the file, a CSV cannot carry a comment line without
     * breaking its parsers, and the filename is the only part of the artefact
     * that survives being forwarded as an email attachment.
     */
    public static function filename(ExportJob $export): string
    {
        $parts = array_filter([
            'kaiki',
            $export->type->value,
            $export->date_basis->value,
            $export->from_date?->toDateString(),
            $export->to_date?->toDateString(),
        ]);

        return str(implode('-', $parts))->slug()->value() . '.csv';
    }

    /** Per tenant, so a misconfigured disk cannot mix two operators' files. */
    private static function directory(ExportJob $export): string
    {
        return 'exports/' . $export->tenant_id;
    }

    public static function disk(): string
    {
        $disk = config('kaiki.exports.disk', 'local');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }

    public static function ttlHours(): int
    {
        $hours = config('kaiki.exports.link_ttl_hours', 24);

        return is_int($hours) && $hours > 0 ? $hours : 24;
    }

    private static function spillBytes(): int
    {
        $mb = config('kaiki.exports.memory_spill_mb', 8);

        return (is_int($mb) && $mb > 0 ? $mb : 8) * 1024 * 1024;
    }
}
