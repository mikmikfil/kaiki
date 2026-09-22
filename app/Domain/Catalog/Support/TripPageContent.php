<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Models\Product;
use App\Rules\ItineraryStopsShape;
use App\Support\Locale\LocaleResolver;

/**
 * The trip page's optional content, between the columns and the panel form
 * (2026-09-16).
 *
 * Five fields: «Τι θα ζήσετε» (`highlights`), the itinerary (`itinerary_stops`),
 * and what is included, not included and worth bringing (`includes`,
 * `excludes`, `what_to_bring`). **All optional** — an empty one is stored as
 * null, which hides its section on the trip page and in the WordPress mirror,
 * and none of them is on the CAT-15 publish checklist.
 *
 * ## Why the form does not edit the columns directly
 *
 * The four lists are translatable arrays (`docs/data-model.md` §3.5). The form
 * edits each locale as a list of lines and this class turns that back into
 * `{"el": [...], "en": [...]}` — blank lines dropped, and every locale empty
 * becoming null rather than `{"el": [], "en": []}`, because §3.5 reads an empty
 * array as "configured as empty" and a form an operator never touched has
 * configured nothing.
 *
 * The itinerary is §3.6's awkward shape: one list per locale joined on `key`,
 * plus a `_geo` sidecar of coordinates that belongs to no locale. An operator
 * thinks of a stop as **one row** with a Greek and an English name, so the form
 * edits rows and this class splits them into the per-locale lists, generating
 * a key for a new stop, keeping the key of an existing one, and carrying over
 * the `_geo` coordinates of every stop that is still there — the form has no
 * field for them, and saving the form must not erase what an import or the API
 * put there. {@see ItineraryStopsShape} still validates the result.
 */
final class TripPageContent
{
    /** The translatable list columns the form edits line by line. */
    public const LISTS = ['highlights', 'includes', 'excludes', 'what_to_bring'];

    /** The form's name for the itinerary rows, so it cannot collide with the column. */
    public const ITINERARY_FIELD = 'itinerary_rows';

    /**
     * One list column as the form holds it: a list of lines per locale.
     *
     * @param  array<string, mixed>  $translations  `getTranslations()` of the column
     * @return array<string, list<string>>
     */
    public static function listToForm(array $translations): array
    {
        $out = [];

        foreach (LocaleResolver::installed() as $locale) {
            $lines = $translations[$locale] ?? [];
            $out[$locale] = is_array($lines)
                ? array_values(array_filter($lines, static fn (mixed $line): bool => is_string($line)))
                : [];
        }

        return $out;
    }

    /**
     * The form's lines back into the column's shape, or null when every locale
     * is empty.
     *
     * @return array<string, list<string>>|null
     */
    public static function listFromForm(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];
        $any = false;

        foreach (LocaleResolver::installed() as $locale) {
            $lines = [];

            foreach ((array) ($value[$locale] ?? []) as $line) {
                // A simple repeater hands back scalars; a keyed one, `['value' => …]`.
                $text = is_array($line) ? ($line['value'] ?? null) : $line;

                if (is_string($text) && trim($text) !== '') {
                    $lines[] = mb_substr(trim($text), 0, 160);
                }
            }

            $out[$locale] = $lines;
            $any = $any || $lines !== [];
        }

        return $any ? $out : null;
    }

    /**
     * The itinerary as rows: one per stop, both languages side by side.
     *
     * Rows follow the order of the first installed locale's list, which is the
     * order the page renders.
     *
     * @return list<array<string, mixed>>
     */
    public static function itineraryToRows(Product $product): array
    {
        $locales = LocaleResolver::installed();
        $translations = $product->itineraryTranslations();
        $byKey = [];

        foreach ($locales as $locale) {
            foreach ((array) ($translations[$locale] ?? []) as $stop) {
                if (! is_array($stop) || ! is_string($stop['key'] ?? null)) {
                    continue;
                }

                $key = $stop['key'];
                $byKey[$key] ??= [
                    'key' => $key,
                    'time' => null,
                    'name' => array_fill_keys($locales, ''),
                    'description' => array_fill_keys($locales, ''),
                    'duration_minutes' => $stop['duration_minutes'] ?? null,
                ];

                $byKey[$key]['name'][$locale] = is_string($stop['name'] ?? null) ? $stop['name'] : '';
                $byKey[$key]['description'][$locale] = is_string($stop['description'] ?? null) ? $stop['description'] : '';
                $byKey[$key]['time'] ??= is_string($stop['time'] ?? null) ? $stop['time'] : null;
            }
        }

        return array_values($byKey);
    }

    /**
     * The form's rows back into §3.6's shape, or null when there are none.
     *
     * @param  array<string, array{lat: float, lng: float}>  $geo  the stored sidecar
     * @return array<string, mixed>|null
     */
    public static function itineraryFromRows(mixed $rows, array $geo = []): ?array
    {
        if (! is_array($rows)) {
            return null;
        }

        $locales = LocaleResolver::installed();
        $stops = array_fill_keys($locales, []);
        $used = [];
        $counter = 1;

        // Existing keys first — the rows' and the stored coordinates' — so a new
        // stop cannot be handed a key that already means another stop, or one
        // that was just deleted, and inherit that stop's coordinates.
        $reserved = array_fill_keys(array_map('strval', array_keys($geo)), true);

        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['key'] ?? null) && $row['key'] !== '') {
                $reserved[$row['key']] = true;
            }
        }

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $names = [];

            foreach ($locales as $locale) {
                $name = $row['name'][$locale] ?? null;
                $names[$locale] = is_string($name) ? trim($name) : '';
            }

            $filled = array_values(array_filter($names, static fn (string $name): bool => $name !== ''));

            // A row with no name in any language is a row the operator added
            // and left; it says nothing and is dropped.
            if ($filled === []) {
                continue;
            }

            $key = is_string($row['key'] ?? null) && $row['key'] !== '' && mb_strlen($row['key']) <= 8 && ! isset($used[$row['key']])
                ? $row['key']
                : null;

            while ($key === null) {
                $candidate = 's' . $counter++;
                $key = isset($used[$candidate]) || isset($reserved[$candidate]) ? null : $candidate;
            }

            $used[$key] = true;

            $time = is_string($row['time'] ?? null) ? trim($row['time']) : '';
            // A time picker hands back seconds; the page and the API want «09:45».
            $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $time, $match) === 1 ? $match[0] : null;

            foreach ($locales as $locale) {
                $description = $row['description'][$locale] ?? null;

                $stop = [
                    'key' => $key,
                    // The other language's name rather than a refused save: §3.6
                    // needs every stop in both, and an operator who wrote the
                    // Greek line should not lose it to a missing English one.
                    'name' => mb_substr($names[$locale] !== '' ? $names[$locale] : $filled[0], 0, 120),
                    'description' => is_string($description) && trim($description) !== '' ? mb_substr(trim($description), 0, 500) : null,
                    'duration_minutes' => is_numeric($row['duration_minutes'] ?? null) ? max(0, (int) $row['duration_minutes']) : null,
                ];

                if ($time !== null) {
                    $stop['time'] = $time;
                }

                $stops[$locale][] = $stop;
            }
        }

        if ($used === []) {
            return null;
        }

        $kept = array_intersect_key($geo, $used);

        return $kept === [] ? $stops : [...$stops, '_geo' => $kept];
    }

    /**
     * The gallery as the uploader holds it: a plain list of paths.
     *
     * Product owner, 2026-09-22: *«ανεβάζουμε όλο το gallery και η πρώτη
     * γίνεται featured»*. So the field is one multi-file uploader rather than a
     * row per photograph — an operator picks twelve files at once and drags the
     * one they want on the card to the front — while `products.images` keeps
     * the `{path, alt}` shape §3.15 defines and the API reads.
     *
     * @param  array<int, mixed>|null  $images
     * @return list<string>
     */
    public static function galleryToForm(?array $images): array
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
     * And back, in the operator's order — the first is the one every card and
     * every search result shows.
     *
     * **The alt text is carried over by path.** The uploader has no field for
     * it, and adding a photograph must not silently blank the descriptions
     * written for the others: a screen reader is the only thing that reads
     * them, so nothing on screen would show the loss.
     *
     * @param  array<int, mixed>|null  $existing  the column as it is stored now
     * @return list<array<string, mixed>>|null
     */
    public static function galleryFromForm(mixed $state, ?array $existing = null): ?array
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
