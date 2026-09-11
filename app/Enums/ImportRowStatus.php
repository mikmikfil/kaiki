<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/** What became of one source record (`import_job_rows.status`, SAA-15). */
enum ImportRowStatus: string
{
    use HasTranslatedLabel;

    /** Read from the file, not yet evaluated against a mapping. */
    case Pending = 'pending';

    /** Will be imported when the operator confirms. */
    case Mapped = 'mapped';

    /** Will not be imported, and `messages` says why. */
    case Skipped = 'skipped';

    /** Became a Kaiki row — `target_type` / `target_id` say which. */
    case Imported = 'imported';

    /** The committer tried and could not; a resumed run tries again. */
    case Failed = 'failed';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Mapped => 'info',
            self::Skipped => 'warning',
            self::Imported => 'success',
            self::Failed => 'danger',
        };
    }
}
