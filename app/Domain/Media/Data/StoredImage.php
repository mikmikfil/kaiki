<?php

declare(strict_types=1);

namespace App\Domain\Media\Data;

use App\Domain\Media\Actions\StoreUploadedImage;
use Spatie\LaravelData\Data;

/**
 * What {@see StoreUploadedImage} wrote to disk.
 *
 * The `path` is the one a column stores; the variants are derived from it and
 * are **not** stored anywhere. That is deliberate (ADR-0021, Option A): the
 * widths live in `config('kaiki.branding.uploads.variants')`, a variant path is
 * a pure function of the original path and a width, and a second copy of that
 * list in the database is the copy that goes stale the day someone changes a
 * number. `php artisan media:rebuild` is what reconciles the disk with the
 * config.
 */
final class StoredImage extends Data
{
    /**
     * @param  array<int, string>  $variants  width in pixels => path on the disk
     */
    public function __construct(
        public readonly string $path,
        public readonly string $mimeType,
        public readonly int $bytes,
        public readonly array $variants = [],
    ) {}

    /**
     * Every path this upload owns, original first.
     *
     * The list a delete walks, so that replacing a logo does not leave three
     * orphaned resizes on the disk for the life of the account.
     *
     * @return list<string>
     */
    public function allPaths(): array
    {
        return [$this->path, ...array_values($this->variants)];
    }
}
