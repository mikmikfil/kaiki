<?php

declare(strict_types=1);

namespace App\Support\Locale;

use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasTranslatableSearch;
use App\Rules\TranslatableRequired;

/**
 * What is inside one translation, and whether there is anything there at all.
 *
 * Three callers need the same two answers and must not disagree about them:
 * {@see HasKaikiTranslations} decides whether to fall back to the next locale,
 * {@see HasTranslatableSearch} flattens a translation into the strings that
 * feed `search_index`, and {@see TranslatableRequired} decides whether an
 * operator has actually filled a field in.
 *
 * The three lived apart until this class existed, and the disagreement was not
 * theoretical: an operator who tabbed through the English inclusions list
 * leaving `["", ""]` had a value the fallback treated as present, the search
 * index treated as two empty strings, and the form treated as filled in. The
 * product then rendered an empty bulleted list on the hosted page with no
 * warning anywhere, because every individual component was behaving correctly.
 */
final class TranslationValue
{
    /**
     * One locale's string out of a raw translation array (§3.1).
     *
     * For the values that never went through a model accessor — a
     * `PriceLineData` label, an image's `alt`, a season name read off a
     * snapshot. The accessor handles the model case and this handles the rest,
     * with the **same within-locale fallback**: §3.1 says a field missing `en`
     * returns the `el` value rather than null, and the reverse also holds.
     *
     * A plain string passes through, so a column written before a field became
     * translatable still reads.
     */
    public static function resolve(mixed $value, ?string $locale = null): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $locale ??= app()->getLocale();

        foreach ([$locale, ...array_keys($value)] as $candidate) {
            $candidate = $value[$candidate] ?? null;

            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Is there nothing a reader could see here?
     *
     * `[]` counts, because the translatable **array** columns (`includes`,
     * `excludes`, `what_to_bring` — `docs/data-model.md` §3.5) fall back on an
     * empty list exactly as a string field falls back on an empty string. So
     * does `[""]`, and so does `["  "]`: an array of blanks is a field nobody
     * filled in, whatever its shape in JSON.
     */
    public static function isBlank(mixed $value): bool
    {
        foreach (self::strings($value) as $string) {
            if (trim($string) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Which of `$locales` this translation set has nothing usable in.
     *
     * The **one** implementation, called by both halves of the §1.6 rule:
     * `HasTranslatableSearch::missingTranslationLocales()` for the observer that
     * refuses the save, and {@see TranslatableRequired} for the form error an
     * operator actually reads.
     *
     * They disagreed while they were two loops, and the disagreement had a
     * direction. `getTranslations()` drops null and empty-string entries but
     * keeps `"   "`, so the observer accepted a whitespace-only title that the
     * form had already refused — meaning the one path with no form on it, the
     * CSV import, was also the one path that could write the value.
     *
     * A non-array `$translations` is **every** locale missing rather than a
     * type error. A plain string arriving where a translation set belongs means
     * a form was built without the translatable component, and "fill in Greek
     * and English" points at that better than "must be an array" does.
     *
     * @param  list<string>  $locales
     * @return list<string>
     */
    public static function missingLocales(mixed $translations, array $locales): array
    {
        $translations = is_array($translations) ? $translations : [];

        $missing = [];

        foreach ($locales as $locale) {
            if (self::isBlank($translations[$locale] ?? null)) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    /**
     * Every string inside a translation, however deeply nested.
     *
     * `products.includes` is a translatable *array* and `itinerary_stops` is a
     * translatable array of objects (`docs/data-model.md` §3.5 and §3.6). A
     * guest searching for a stop on the itinerary has to find the product it
     * belongs to, so the haystack cannot stop at the first level.
     *
     * Non-strings are dropped rather than cast. `itinerary_stops` entries carry
     * a `duration_minutes`, and a guest searching `45` must not match every
     * product with a 45-minute stop on it.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            foreach (self::strings($item) as $string) {
                $strings[] = $string;
            }
        }

        return $strings;
    }
}
