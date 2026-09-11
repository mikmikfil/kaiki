<?php

declare(strict_types=1);

namespace App\Domain\Import\Exceptions;

use RuntimeException;

/**
 * A file the importer could not read, with the lang key that says so in Greek
 * (NFR-8). The job that catches it writes the translated sentence onto the
 * import, so the operator reads what to do rather than a parser's complaint.
 */
final class ImportFileUnreadable extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct($key);
    }

    public static function wxr(): self
    {
        return new self('imports.errors.wxr_unreadable');
    }

    public static function csv(): self
    {
        return new self('imports.errors.csv_unreadable');
    }

    public static function csvColumns(): self
    {
        return new self('imports.errors.csv_columns');
    }

    public static function missing(): self
    {
        return new self('imports.errors.file_missing');
    }
}
