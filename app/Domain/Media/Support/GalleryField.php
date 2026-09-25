<?php

declare(strict_types=1);

namespace App\Domain\Media\Support;

/**
 * Between a multi-file uploader and the `{path, alt}` gallery shape (§3.15).
 *
 * Both sides of one idea, and neither belongs to any single resource: a trip
 * has a gallery, a boat has a gallery, and §3.15 gives them the same column
 * shape. This started life inside `TripPageContent` because trips got the
 * uploader first (2026-09-22); it moved here when boats got the same one
 * (2026-09-23), rather than leaving `VesselResource` calling something named
 * after trip pages.
 *
 * ## The order is the only thing that names the cover
 *
 * Product owner, 2026-09-22: *«ανεβάζουμε όλο το gallery και η πρώτη γίνεται
 * featured»*. So the field is one multi-file uploader rather than a row per
 * photograph — an operator picks twelve files at once and drags the one that
 * should lead to the front — and `ImagePayload` hands the list back in that
 * order, with every card and now the boat's rail taking `[0]`.
 *
 * An `is_cover` flag would be a second answer to the same question, and the two
 * would disagree the first time somebody reordered without clicking it.
 */
final class GalleryField
{
    /**
     * The gallery as the uploader holds it: a plain list of paths.
     *
     * @param  array<int, mixed>|null  $images
     * @return list<string>
     */
    public static function toForm(?array $images): array
    {
        $paths = [];

        foreach ($images ?? [] as $image) {
            $path = is_array($image) ? ($image['path'] ?? null) : $image;

            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * And back, in the operator's order — the first is the one every card, and
     * the boat's rail, opens with.
     *
     * **The alt text is carried over by path.** The uploader has no field for
     * it, and adding a photograph must not silently blank the descriptions
     * written for the others: a screen reader is the only thing that reads
     * them, so nothing on screen would show the loss.
     *
     * @param  array<int, mixed>|null  $existing  the column as it is stored now
     * @return list<array<string, mixed>>|null
     */
    public static function fromForm(mixed $state, ?array $existing = null): ?array
    {
        if (! is_array($state)) {
            return null;
        }

        $alts = [];

        foreach ($existing ?? [] as $image) {
            if (is_array($image) && is_string($image['path'] ?? null) && isset($image['alt'])) {
                $alts[$image['path']] = $image['alt'];
            }
        }

        $images = [];

        foreach ($state as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $image = ['path' => $path];

            if (isset($alts[$path])) {
                $image['alt'] = $alts[$path];
            }

            $images[] = $image;
        }

        // An empty list rather than null: an operator who removed the last
        // photograph means the gallery is empty, and `ImagePayload` reads both
        // the same way.
        return $images;
    }
}
