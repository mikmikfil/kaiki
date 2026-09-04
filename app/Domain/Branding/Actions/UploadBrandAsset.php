<?php

declare(strict_types=1);

namespace App\Domain\Branding\Actions;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Enums\BrandAsset;
use App\Exceptions\UploadRefused;
use App\Models\BrandProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Put a file in one of the four brand slots, and take the old one off the disk
 * (spec BRD-7, SEC-13, ADR-0021).
 *
 * {@see StoreUploadedImage} decides whether the bytes are acceptable and what
 * they become; this decides *where they belong* — which column, which variant
 * widths, whose directory — and cleans up after the file being replaced.
 *
 * ## The old file is deleted after the new one is written, never before
 *
 * If the upload is refused, the operator still has the logo they had this
 * morning. Deleting first would mean a rejected 3 MB PNG leaves an operator
 * with no logo at all on every booking page they own, and the sentence they get
 * back is about a file size.
 *
 * ## Variants are deleted with the original
 *
 * `logo/x.png` implies `logo/x-200.png` and `logo/x-400.png`. Deleting only the
 * path the column stored would leave the resizes behind forever — invisible,
 * because nothing references them, and never noticed, because nobody lists that
 * directory.
 */
final class UploadBrandAsset
{
    public function __construct(private readonly StoreUploadedImage $store) {}

    /**
     * @return string the stored path, which is what the column holds
     *
     * @throws UploadRefused
     */
    public function __invoke(BrandProfile $profile, BrandAsset $asset, UploadedFile $file): string
    {
        $stored = ($this->store)(
            file: $file,
            directory: $asset->directory($profile->tenant_id),
            variantWidths: $asset->variantWidths(),
        );

        $previous = $profile->getAttribute($asset->column());

        $profile->setAttribute($asset->column(), $stored->path);
        $profile->save();

        if (is_string($previous) && $previous !== '') {
            $this->forget($previous, $asset);
        }

        return $stored->path;
    }

    /**
     * Remove a slot's file and every variant of it, and blank the column.
     *
     * The operator-facing "remove logo" as well as the internal half of a
     * replacement, so there is one definition of what removing a brand asset
     * clears up.
     */
    public function remove(BrandProfile $profile, BrandAsset $asset): void
    {
        $path = $profile->getAttribute($asset->column());

        $profile->setAttribute($asset->column(), null);
        $profile->save();

        if (is_string($path) && $path !== '') {
            $this->forget($path, $asset);
        }
    }

    /** Delete a path and the variants implied by its slot's widths. */
    private function forget(string $path, BrandAsset $asset): void
    {
        $disk = Storage::disk((string) config('kaiki.branding.uploads.disk'));

        $disk->delete($path);

        foreach ($asset->variantWidths() as $width) {
            // Deleting a path that was never written — an SVG has no variants,
            // and a width above the source was skipped — is a no-op on every
            // driver, so this does not need to know which ones exist.
            $disk->delete(StoreUploadedImage::variantPath($path, $width));
        }
    }
}
