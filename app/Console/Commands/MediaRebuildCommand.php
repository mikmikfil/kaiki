<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\Support\ImageContentType;
use App\Enums\BrandAsset;
use App\Models\BrandProfile;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Interfaces\ImageManagerInterface;
use Throwable;

/**
 * Regenerate the resized variants of every stored brand asset (ADR-0021, Option A).
 *
 * ADR-0021 accepted synchronous conversions at *fixed documented sizes*, and
 * the obvious question about that is what happens when a size changes. This is
 * the answer, and it is the reason the widths can live in config at all:
 * changing a number in `config('kaiki.branding.uploads.variants')` changes
 * nothing already on disk until this runs.
 *
 * ## Originals are never touched
 *
 * Only variants are written. The original is the file the operator uploaded
 * (re-encoded once, at upload, to strip metadata), and re-encoding it again on
 * every rebuild would degrade a lossy format a little more each time for no
 * gain. A rebuild is therefore safe to run repeatedly and safe to run twice by
 * accident.
 *
 * ## Across tenants, deliberately
 *
 * A size change is a platform decision and applies to every operator, so this
 * runs inside {@see Tenancy::withoutTenancy()} — one of the small number of
 * explicit opt-outs that `grep withoutTenancy` is meant to find.
 */
final class MediaRebuildCommand extends Command
{
    protected $signature = 'media:rebuild {--tenant= : Only this tenant id} {--prune : Delete variants at widths no longer configured}';

    protected $description = 'Regenerate resized variants of brand assets after a change to the configured widths (ADR-0021).';

    public function handle(ImageManagerInterface $images): int
    {
        $disk = Storage::disk((string) config('kaiki.branding.uploads.disk'));
        $tenantId = $this->option('tenant');

        $rebuilt = 0;
        $skipped = 0;
        $pruned = 0;

        $profiles = Tenancy::withoutTenancy(static function () use ($tenantId): iterable {
            $query = BrandProfile::query();

            if ($tenantId !== null) {
                $query->where('tenant_id', (int) $tenantId);
            }

            return $query->get();
        });

        foreach ($profiles as $profile) {
            foreach (BrandAsset::cases() as $asset) {
                $path = $profile->getAttribute($asset->column());

                if (! is_string($path) || $path === '') {
                    continue;
                }

                if (! $disk->exists($path)) {
                    // A column pointing at a file that is not there is a real
                    // problem — a half-finished restore, a disk swapped without
                    // its contents — and silently skipping it is how it stays
                    // unnoticed until a guest sees a broken image.
                    $this->components->warn(sprintf('%s: %s is missing from the disk.', $asset->value, $path));
                    $skipped++;

                    continue;
                }

                if ($this->option('prune')) {
                    $pruned += $this->prune($disk, $path, $asset);
                }

                $rebuilt += $this->rebuild($images, $disk, $path, $asset);
            }
        }

        $this->components->info(sprintf('%d variants written, %d pruned, %d assets skipped.', $rebuilt, $pruned, $skipped));

        return self::SUCCESS;
    }

    /** @return int the number of variants written */
    private function rebuild(ImageManagerInterface $images, Filesystem $disk, string $path, BrandAsset $asset): int
    {
        $contents = $disk->get($path);

        if (! is_string($contents)) {
            return 0;
        }

        $mimeType = ImageContentType::detect($contents);

        // An SVG has no variants and never did — it is resolution-independent,
        // and rasterising it here would invent files the uploader deliberately
        // did not create.
        if ($mimeType === null || ! ImageContentType::isRaster($mimeType)) {
            return 0;
        }

        try {
            $sourceWidth = $images->decodeBinary($contents)->width();
        } catch (Throwable) {
            $this->components->warn(sprintf('%s: %s could not be decoded.', $asset->value, $path));

            return 0;
        }

        $written = 0;

        foreach ($asset->variantWidths() as $width) {
            if ($width >= $sourceWidth) {
                continue;
            }

            $variant = $images->decodeBinary($contents)->scaleDown(width: $width);

            $disk->put(
                StoreUploadedImage::variantPath($path, $width),
                (string) $variant->encodeUsingMediaType($mimeType),
            );

            $written++;
        }

        return $written;
    }

    /**
     * Delete variants at widths that are no longer configured.
     *
     * Opt-in, because it is the one destructive thing this command can do and
     * the widths live in a config file a deploy can change by accident. A
     * rebuild that quietly deleted the 400px logo because a bad merge dropped a
     * line would be discovered from a broken page, not from this output.
     *
     * The candidates come from **listing the directory**, not from the config.
     * A width that was removed from the config is no longer in it to find, so a
     * config-driven prune could only ever delete widths that moved between
     * slots — which is the one case that does not matter. Matching is pinned to
     * this original's own name and extension (`{name}-{width}.{ext}`), so a file
     * that has nothing to do with the variant scheme is never a candidate.
     */
    private function prune(Filesystem $disk, string $path, BrandAsset $asset): int
    {
        $keep = $asset->variantWidths();
        $directory = (string) pathinfo($path, PATHINFO_DIRNAME);
        $name = (string) pathinfo($path, PATHINFO_FILENAME);
        $extension = (string) pathinfo($path, PATHINFO_EXTENSION);

        $pattern = '/^' . preg_quote($name, '/') . '-(\d+)\.' . preg_quote($extension, '/') . '$/';
        $deleted = 0;

        foreach ($disk->files($directory) as $candidate) {
            if (preg_match($pattern, basename($candidate), $matches) !== 1) {
                continue;
            }

            if (in_array((int) $matches[1], $keep, true)) {
                continue;
            }

            $disk->delete($candidate);
            $deleted++;
        }

        return $deleted;
    }
}
