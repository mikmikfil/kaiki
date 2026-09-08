<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Operations\Actions\GenerateManifest;
use App\Enums\AuditAction;
use App\Enums\ExportDateBasis;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Database\Factories\ExportJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One bookings or guests CSV an operator asked for (spec OPS-17, OPS-18).
 *
 * ## The row is the log OPS-18 asks for
 *
 * *"…and are logged"*. Not in an audit trail — these are exports of the
 * operator's own business data with no document numbers in them, and
 * {@see AuditAction::ManifestGenerated} exists precisely because the
 * passenger list is the one export that *is* a disclosure. Filling the audit
 * trail with rows for every accounting CSV is how a trail stops being read, and
 * that argument is already written down in {@see GenerateManifest}.
 *
 * So the log is this table: who asked, for what window, how many rows came out,
 * and whether anybody ever downloaded it.
 *
 * ## Expiry is answered here, not by the sweeper
 *
 * The same asymmetry `bookings.hold_expires_at` has (§2.5). A purge job that
 * falls behind must never leave a link working past its day, and one that runs
 * early must never break a download in flight. So {@see self::hasExpired()} is
 * the authority every read consults, and the sweeper only tidies the file off
 * the disk. With the scheduler stopped, the link still stops working on time.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $user_id
 * @property ExportType $type
 * @property ExportDateBasis $date_basis
 * @property Carbon|null $from_date
 * @property Carbon|null $to_date
 * @property array<string, mixed> $filters
 * @property ExportStatus $status
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $filename
 * @property int $row_count
 * @property int $byte_size
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $downloaded_at
 * @property int $download_count
 * @property Carbon $created_at
 */
class ExportJob extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ExportJobFactory> */
    use HasFactory;

    use HasUuid;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ExportType::class,
            'date_basis' => ExportDateBasis::class,
            'from_date' => 'date',
            'to_date' => 'date',
            'filters' => 'array',
            'status' => ExportStatus::class,
            'row_count' => 'integer',
            'byte_size' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'download_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Has this export aged out?
     *
     * A row with no `expires_at` has not finished yet, and an unfinished export
     * is not an expired one — returning true here would make every queued job
     * unreachable the moment it was created.
     */
    public function hasExpired(?Carbon $now = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isBefore($now ?? Carbon::now());
    }

    /**
     * May this row's file be handed over right now?
     *
     * Both questions, deliberately. The status says the job finished and the
     * timestamp says the day has not passed — and the timestamp is checked even
     * when the sweeper has not run, because a link that works past its expiry
     * only when the scheduler is unhealthy is a link nobody can reason about.
     */
    public function isDownloadable(?Carbon $now = null): bool
    {
        return $this->status->isDownloadable() && ! $this->hasExpired($now);
    }

    /**
     * Rows the purge sweeper should visit.
     *
     * **Cross-tenant**, so it deliberately does not filter by tenant — it runs
     * from the scheduler, where there is no current tenant, and matches
     * `export_jobs_purge_idx`.
     *
     * @param  Builder<ExportJob>  $query
     * @return Builder<ExportJob>
     */
    public function scopeDueForPurge(Builder $query, ?Carbon $now = null): Builder
    {
        return $query
            ->where('status', ExportStatus::Ready)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now ?? Carbon::now());
    }

    /**
     * @param  Builder<ExportJob>  $query
     * @return Builder<ExportJob>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
