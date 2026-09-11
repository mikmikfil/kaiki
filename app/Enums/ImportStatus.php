<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The life of one import (`import_jobs.status`, SAA-14, SAA-15).
 *
 * `analysing` → `mapping_review` is the dry run: nothing in the catalogue or
 * the bookings has been touched. Only `running` writes, and a `failed` run is
 * resumable — the committer skips every row already `imported`.
 */
enum ImportStatus: string
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Analysing = 'analysing';
    case MappingReview = 'mapping_review';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Analysing, self::Running => 'info',
            self::MappingReview => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** May the operator still change the mapping and start (or resume) the import? */
    public function isReviewable(): bool
    {
        return $this === self::MappingReview || $this === self::Failed;
    }

    /** Is a queued job working on it right now? */
    public function isBusy(): bool
    {
        return $this === self::Pending || $this === self::Analysing || $this === self::Running;
    }
}
