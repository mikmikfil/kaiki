<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Branding\Support\CssSanitizer;
use App\Domain\Branding\Support\SvgSanitizer;
use App\Domain\Media\Data\StoredImage;
use App\Domain\Media\Support\ImageContentType;
use App\Exceptions\UploadRefused;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Interfaces\ImageManagerInterface;
use Throwable;

/**
 * The one place an uploaded image is validated, cleaned, resized and written
 * (ADR-0021 Option A, spec BRD-7, SEC-13).
 *
 * ADR-0021 rejected `spatie/laravel-medialibrary` — a package, a polymorphic
 * table and a new tenancy-scoping problem for the sake of a handful of upload
 * fields — in favour of plain path columns and *this*. The whole of that
 * decision rests on there being exactly one writer, so an upload arriving from
 * the panel, from the API or from an import is judged identically. Nothing
 * anywhere else calls `Storage::put()` with an operator's bytes.
 *
 * ## What "validated" means here
 *
 * Three checks, in the order that makes each one cheap:
 *
 * 1. **Size**, from `config('kaiki.branding.uploads.max_kilobytes')`.
 * 2. **Content type from the bytes** ({@see ImageContentType}) — not the
 *    extension, and not the browser-supplied `Content-Type`, both of which the
 *    uploader controls (SEC-13).
 * 3. **SVG is sanitised or refused** ({@see SvgSanitizer}). There is no third
 *    outcome: a file that cannot be parsed into an `<svg>` root is not stored.
 *
 * ## EXIF is stripped by re-encoding, not by editing
 *
 * BRD-7 requires metadata to be gone. A stripper that walks the chunks of a PNG
 * and deletes the ones it recognises leaves behind the ones it does not, and
 * the list of what a camera or a design tool can embed is open-ended — GPS in
 * EXIF, an author name in XMP, a colour profile carrying a comment. Decoding
 * the pixels and encoding a **new file** from them keeps exactly the pixels and
 * nothing else, which is a guarantee rather than a list. It costs one re-encode
 * on an upload that already resizes.
 *
 * ## Conversions are synchronous
 *
 * ADR-0021 is explicit: *"conversions are synchronous on upload at fixed
 * documented sizes — do not queue conversions."* A queued conversion means an
 * operator sees their old logo for however long the queue is behind, on a page
 * whose entire purpose is showing them their new one, and a failed job means
 * they never see it at all with nothing on screen to say so. Three resizes of a
 * 2 MB image is a few hundred milliseconds.
 */
final class StoreUploadedImage
{
    public function __construct(private readonly ImageManagerInterface $images) {}

    /**
     * Validate, clean, resize and write. Returns the paths, or throws.
     *
     * @param  list<int>  $variantWidths  fixed widths from config, never a caller's arithmetic
     *
     * @throws UploadRefused
     */
    public function __invoke(UploadedFile $file, string $directory, array $variantWidths = [], ?string $disk = null): StoredImage
    {
        $contents = $this->read($file);

        $this->refuseIfTooLarge($contents);

        $mimeType = ImageContentType::detect($contents);

        if ($mimeType === null) {
            throw UploadRefused::unsupportedType();
        }

        $filesystem = Storage::disk($disk ?? (string) config('kaiki.branding.uploads.disk'));

        // A random name, not the operator's filename. Three reasons and they
        // all matter: an uploaded name is attacker-controlled and ends up in a
        // path; two operators both uploading `logo.png` must not collide; and a
        // replaced file gets a new URL, so a cached logo cannot linger after
        // the operator has changed it.
        $name = (string) Str::ulid();

        return $mimeType === ImageContentType::SVG
            ? $this->storeSvg($filesystem, $contents, $directory, $name)
            : $this->storeRaster($filesystem, $contents, $directory, $name, $mimeType, $variantWidths);
    }

    /**
     * The bytes, from wherever the file currently is.
     *
     * `get()` rather than `getContent()` so a Livewire `TemporaryUploadedFile`
     * — which is what the Filament page hands over — works unchanged.
     */
    private function read(UploadedFile $file): string
    {
        $contents = $file->get();

        return is_string($contents) ? $contents : '';
    }

    /** @throws UploadRefused */
    private function refuseIfTooLarge(string $contents): void
    {
        $limit = (int) config('kaiki.branding.uploads.max_kilobytes');
        $kilobytes = (int) ceil(strlen($contents) / 1024);

        if ($kilobytes > $limit) {
            throw UploadRefused::tooLarge($kilobytes, $limit);
        }
    }

    /**
     * SVG: sanitised, stored as-is, and **never rasterised**.
     *
     * No variants, because an SVG is already resolution-independent and
     * generating a 200px PNG from one throws away the only reason to accept the
     * format. A `<style>` block or an inline `style` attribute has been through
     * {@see CssSanitizer} by the time this runs, so
     * what lands on disk is what a browser may safely render.
     *
     * @throws UploadRefused
     */
    private function storeSvg(Filesystem $filesystem, string $contents, string $directory, string $name): StoredImage
    {
        $safe = SvgSanitizer::sanitize($contents);

        if ($safe === null) {
            throw UploadRefused::unsafeSvg();
        }

        $path = "{$directory}/{$name}.svg";

        $filesystem->put($path, $safe);

        return new StoredImage(
            path: $path,
            mimeType: ImageContentType::SVG,
            bytes: strlen($safe),
        );
    }

    /**
     * PNG and WebP: decoded, re-encoded without metadata, then resized.
     *
     * @param  list<int>  $variantWidths
     *
     * @throws UploadRefused
     */
    private function storeRaster(
        Filesystem $filesystem,
        string $contents,
        string $directory,
        string $name,
        string $mimeType,
        array $variantWidths,
    ): StoredImage {
        $extension = ImageContentType::extensionFor($mimeType);
        $path = "{$directory}/{$name}.{$extension}";

        try {
            $image = $this->images->decodeBinary($contents);
        } catch (Throwable) {
            // The signature said PNG and the decoder disagreed. That is either
            // a truncated upload or a file built to trip the decoder, and
            // neither is something to store.
            throw UploadRefused::undecodable();
        }

        $sourceWidth = $image->width();

        // The re-encode *is* the EXIF strip: what comes back is built from the
        // decoded pixels, so no chunk of the original file survives into it.
        $original = (string) $image->encodeUsingMediaType($mimeType);

        $filesystem->put($path, $original);

        $variants = [];

        foreach ($variantWidths as $width) {
            // **No upscaling.** A 120px favicon asked to become 180px is a
            // bigger file with no more detail in it, and the browser would have
            // done the same interpolation for free. The width is skipped
            // instead, so a variant that exists on disk is always a real
            // resize, and a consumer that finds none falls back to the
            // original — which is the same pixels either way.
            if ($width >= $sourceWidth) {
                continue;
            }

            $resized = $this->images->decodeBinary($contents)->scaleDown(width: $width);

            $variantPath = self::variantPath($path, $width);
            $filesystem->put($variantPath, (string) $resized->encodeUsingMediaType($mimeType));

            $variants[$width] = $variantPath;
        }

        return new StoredImage(
            path: $path,
            mimeType: $mimeType,
            bytes: strlen($original),
            variants: $variants,
        );
    }

    /**
     * Where a variant of `$path` at `$width` lives.
     *
     * A pure function of the two, so nothing has to store it: `logo/x.png` at
     * 200 is always `logo/x-200.png`. `media:rebuild` relies on this to find
     * what is already on disk, and the widget URL builder in M3 will rely on it
     * to name a file it has never seen.
     */
    public static function variantPath(string $path, int $width): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $withoutExtension = $extension === '' ? $path : substr($path, 0, -(strlen($extension) + 1));

        return "{$withoutExtension}-{$width}.{$extension}";
    }
}
