<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Database\Factories\ImportJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One import from WooCommerce / YITH Booking (spec SAA-13 … SAA-15).
 *
 * The row is the import's whole state: which files, the mapping the operator
 * reviewed (§3.7), the counts, and a short human log. The per-record detail —
 * what each product and booking became, or why it did not — is in
 * {@see ImportJobRow}.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property ImportSource $source
 * @property ImportStatus $status
 * @property bool $is_dry_run
 * @property array<string, mixed>|null $connection
 * @property array<string, mixed> $mapping
 * @property array<string, mixed> $stats
 * @property string|null $log
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $created_by_user_id
 * @property Carbon $created_at
 */
class ImportJob extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ImportJobFactory> */
    use HasFactory;

    use HasUuid;

    /** The data model's cap on `log` (§2.7). */
    public const LOG_LIMIT_BYTES = 65536;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'is_dry_run' => true,
        'mapping' => '{}',
        'stats' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => ImportSource::class,
            'status' => ImportStatus::class,
            'is_dry_run' => 'boolean',
            'connection' => 'encrypted:array',
            'mapping' => 'array',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<ImportJobRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportJobRow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Append a line to the human log, keeping only the last 64 KB.
     *
     * The tail rather than the head: on a long import the lines worth reading
     * are the last ones — where it stopped, and why.
     */
    public function appendLog(string $line): void
    {
        $log = ($this->log === null ? '' : $this->log . "\n") . '[' . Carbon::now()->toDateTimeString() . '] ' . $line;

        if (strlen($log) > self::LOG_LIMIT_BYTES) {
            $log = substr($log, -self::LOG_LIMIT_BYTES);
        }

        $this->log = $log;
    }
}
