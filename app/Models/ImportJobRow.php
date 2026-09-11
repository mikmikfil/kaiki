<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportRowStatus;
use App\Enums\ImportRowType;
use App\Enums\ProductCategory;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ImportJobRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One source record, and what became of it (SAA-14, SAA-15).
 *
 * `messages` holds translation **keys** with their parameters rather than
 * sentences. The row outlives the request that wrote it and may be read by a
 * different person in a different language; a key renders in whichever one
 * the reader has.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $import_job_id
 * @property ImportRowType $source_type
 * @property string $source_id
 * @property array<string, mixed> $source_payload
 * @property string|null $target_type
 * @property int|null $target_id
 * @property ImportRowStatus $status
 * @property list<array{key: string, params?: array<string, scalar|null>}> $messages
 */
class ImportJobRow extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ImportJobRowFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'source_payload' => '{}',
        'messages' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_type' => ImportRowType::class,
            'status' => ImportRowStatus::class,
            'source_payload' => 'array',
            'messages' => 'array',
            'target_id' => 'integer',
        ];
    }

    /** @return BelongsTo<ImportJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }

    /**
     * The messages, rendered in the current locale.
     *
     * @return list<string>
     */
    public function renderedMessages(): array
    {
        $out = [];

        foreach ($this->messages as $message) {
            $params = $message['params'] ?? [];

            // A category is stored as its enum value and shown as its label, in
            // whichever language the reader has.
            if (is_string($params['category'] ?? null) && ProductCategory::tryFrom($params['category']) !== null) {
                $params['category'] = ProductCategory::from($params['category'])->label();
            }

            $out[] = (string) __($message['key'], $params);
        }

        return $out;
    }

    /** A short human label for the review screen: the title, the name, or the id. */
    public function label(): string
    {
        $payload = $this->source_payload;

        foreach (['title', 'name', 'customer', 'product'] as $key) {
            if (is_string($payload[$key] ?? null) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return '#' . $this->source_id;
    }
}
