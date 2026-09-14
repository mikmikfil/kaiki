<?php

declare(strict_types=1);

namespace App\Domain\Branding\Actions;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Enums\BrandAsset;
use App\Exceptions\UploadRefused;
use App\Models\BrandProfile;
use App\Models\PlatformBrand;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Put a file in one of the platform's own brand slots, and take the old one off
 * the disk.
 *
 * ## Why this is a sibling of UploadBrandAsset and not a parameter on it
 *
 * {@see UploadBrandAsset} takes a {@see BrandProfile} and files
 * under that operator's tenant id. Widening it to "any model, any directory"
 * would make the one class that knows *whose* file this is stop knowing — and
 * that class is the reason a listing of the disk never mixes two operators
 * together. Two small classes that each know exactly one answer are cheaper to
 * be sure about than one that takes the answer as an argument.
 *
 * Everything that decides whether the bytes are acceptable — the magic-byte
 * check, the SVG sanitiser, the EXIF strip, the resizes — is
 * {@see StoreUploadedImage}, which both call and neither reimplements. This
 * file contains no security decision of its own, which is the point.
 *
 * ## The old file is deleted after the new one is written, never before
 *
 * A refused upload leaves the logo that was there this morning in place. The
 * alternative is a platform with no logo on every panel because somebody tried
 * a 3 MB PNG.
 */
final class UploadPlatformAsset
{
    public function __construct(private readonly StoreUploadedImage $store) {}

    /**
     * @return string the stored path, which is what the column holds
     *
     * @throws UploadRefused
     */
    public function __invoke(PlatformBrand $brand, BrandAsset $asset, UploadedFile $file): string
    {
        $stored = ($this->store)(
            file: $file,
            directory: $asset->platformDirectory(),
            variantWidths: $asset->variantWidths(),
        );

        $previous = $brand->getAttribute($asset->column());

        $brand->setAttribute($asset->column(), $stored->path);
        $brand->save();

        if (is_string($previous) && $previous !== '') {
            $this->forget($previous, $asset);
        }

        return $stored->path;
    }

    /** Remove a slot's file and every variant of it, and blank the column. */
    public function remove(PlatformBrand $brand, BrandAsset $asset): void
    {
        $path = $brand->getAttribute($asset->column());

        $brand->setAttribute($asset->column(), null);
        $brand->save();

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
            $disk->delete(StoreUploadedImage::variantPath($path, $width));
        }
    }
}
