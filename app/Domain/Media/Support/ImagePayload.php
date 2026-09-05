<?php

declare(strict_types=1);

namespace App\Domain\Media\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A `{path, alt: {el, en}}` gallery column, shaped as the API's `ProductImage`
 * (`docs/api.md` §5, `docs/data-model.md` §3.15).
 *
 * One implementation because three columns hold this shape — `products.images`,
 * `vessels.images` and the single `ports.photo_path` — and every one of them is
 * read by `GET /products`, `GET /products/{uuid}` and, in M3, the hosted page.
 * Three copies of "resolve the alt text for this locale, then build a URL" is
 * how one of them ends up returning the raw translation array to a guest.
 *
 * ## Alt text falls back rather than going null
 *
 * §3.1's within-locale fallback: a missing `en` alt returns the `el` one. An
 * image with no alt at all is `null`, which is a correct statement to a screen
 * reader; an image whose alt exists in the other language and is dropped is
 * not.
 *
 * ## `width` and `height` are always null, and that is not an oversight
 *
 * ADR-0021 Option A stores the path and derives variants from config, so the
 * dimensions are not in the database and reading them would mean a filesystem
 * stat per image on the hottest catalogue read. The contract types both as
 * nullable; a client that lays out from them already has to cope with null.
 */
final class ImagePayload
{
    /**
     * @param  array<int, array<string, mixed>>|null  $images
     * @return list<array{url: string, alt: string|null, width: int|null, height: int|null}>
     */
    public static function collection(?array $images, string $locale): array
    {
        $payload = [];

        foreach ($images ?? [] as $image) {
            if (! is_array($image)) {
                continue;
            }

            $url = self::url($image['path'] ?? null);

            // An entry whose file is gone, or whose disk cannot address it, is
            // skipped rather than emitted with a null URL. The contract marks
            // `url` required, and a gallery with a hole in it is worse for the
            // page than a gallery with one fewer photo.
            if ($url === null) {
                continue;
            }

            $payload[] = [
                'url' => $url,
                'alt' => self::alt($image['alt'] ?? null, $locale),
                'width' => null,
                'height' => null,
            ];
        }

        return $payload;
    }

    /**
     * A single stored path as an absolute URL, or null when there is nothing to
     * link to.
     */
    public static function url(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            return Storage::disk((string) config('kaiki.catalog.uploads.disk', 'public'))->url($path);
        } catch (Throwable) {
            // `Storage::url()` throws on a driver with no public URL — the
            // `local` disk does exactly that. A misconfigured disk must not
            // 500 the catalogue for every visitor; it degrades to no image.
            return null;
        }
    }

    /**
     * The alt text for one locale, with the §3.1 within-locale fallback.
     *
     * Accepts the raw translation array as stored, and also a plain string, so
     * a column written before the field became translatable still reads.
     */
    public static function alt(mixed $alt, string $locale): ?string
    {
        if (is_string($alt)) {
            return $alt === '' ? null : $alt;
        }

        if (! is_array($alt)) {
            return null;
        }

        foreach ([$locale, ...array_keys($alt)] as $candidate) {
            $value = $alt[$candidate] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
