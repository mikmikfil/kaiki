<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where an export has got to (spec OPS-18, NFR-8).
 *
 * ## `expired` is a state, not the absence of a file
 *
 * The tempting model has four states and treats expiry as "the file is gone".
 * Then the panel shows a `ready` export whose download button produces a 404,
 * and the operator's reasonable conclusion is that the product lost their file.
 *
 * An export that has aged out says so, keeps its row count and its date window,
 * and offers to run again. Nothing is lost that the operator cannot get back in
 * thirty seconds, and the screen never lies about what it has.
 */
enum ExportStatus: string
{
    use HasTranslatedLabel;

    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    /** Is the operator still waiting on this one? */
    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    /** Is there a file to hand over right now? */
    public function isDownloadable(): bool
    {
        return $this === self::Ready;
    }

    /** Is there a file on disk that a purge would have to delete? */
    public function holdsFile(): bool
    {
        return $this === self::Ready;
    }

    /**
     * The Filament badge colour.
     *
     * `expired` is grey rather than red: it is the ordinary end of an export's
     * life, and colouring it as a failure would teach an operator to read a
     * list of normal rows as a list of problems.
     */
    public function color(): string
    {
        return match ($this) {
            self::Queued, self::Processing => 'warning',
            self::Ready => 'success',
            self::Failed => 'danger',
            self::Expired => 'gray',
        };
    }
}
