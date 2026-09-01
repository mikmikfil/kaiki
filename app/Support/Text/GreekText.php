<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * Accent- and sigma-insensitive folding for Greek text (I18N-7).
 *
 * ADR-0008's acceptance note put this here rather than in #15: *"Accent folding
 * is a shared helper, not per-call-site. Greek tonos and final-sigma
 * normalisation is used by both the translatable observer and the query
 * builder, so it behaves identically on MySQL and SQLite."*
 *
 * That last clause is the whole reason it is PHP rather than SQL. Collations
 * differ — MySQL's `utf8mb4_unicode_ci` folds tonos, SQLite's `NOCASE` does not
 * fold anything outside ASCII — so a search implemented in the database would
 * return different rows locally than in production, and the local result is the
 * one a developer trusts. Folding in PHP on the way *in* to `search_index` and
 * on the way *in* to the query means both sides compare bytes that were
 * produced by this one function.
 *
 * What it does, in order:
 *
 *   1. lowercase — `ΆΝΝΑ` → `άννα`
 *   2. strip tonos and dialytika — `άννα` → `αννα`, `προϊόν` → `προιον`
 *   3. normalise final sigma — `Οδυσσεύς` → `οδυσσευσ`
 *
 * Step 3 matters more than it looks. Greek writes the same letter differently
 * at the end of a word, so a guest searching for `οδυσσεύς` and a vessel stored
 * as `Οδυσσέας` must reach the same key — and an operator typing a name into a
 * search box types it without accents about half the time.
 */
final class GreekText
{
    /**
     * Lowercase Greek letters that carry a diacritic, mapped to their bare form.
     *
     * Uppercase forms are absent on purpose: lowercasing runs first, so `Ά` has
     * already become `ά` by the time this map is applied. Listing both would be
     * two places to forget a letter.
     */
    private const DIACRITICS = [
        'ά' => 'α',
        'έ' => 'ε',
        'ή' => 'η',
        'ί' => 'ι',
        'ό' => 'ο',
        'ύ' => 'υ',
        'ώ' => 'ω',
        'ϊ' => 'ι',
        'ϋ' => 'υ',
        'ΐ' => 'ι',
        'ΰ' => 'υ',
        'ς' => 'σ',
    ];

    /**
     * The comparable form of a string.
     *
     * Idempotent: folding a folded string returns it unchanged, which is what
     * lets the observer rebuild `search_index` without caring whether the value
     * it was handed has already been through here.
     */
    public static function fold(string $value): string
    {
        $lowered = mb_strtolower($value, 'UTF-8');

        return strtr($lowered, self::DIACRITICS);
    }

    /**
     * A folded, whitespace-collapsed haystack for `search_index`.
     *
     * Collapsing runs of whitespace means a name pasted out of a spreadsheet
     * with a double space still matches the same name typed by hand.
     *
     * @param  array<array-key, string|null>  $values  one per locale, nulls skipped
     */
    public static function searchIndex(array $values): string
    {
        $parts = [];

        foreach ($values as $value) {
            if ($value === null || trim($value) === '') {
                continue;
            }

            $parts[] = self::fold($value);
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * Does `$haystack` contain `$needle`, ignoring case, accents and final sigma?
     *
     * The query-builder half of the pair. Both sides go through `fold()`, so a
     * caller cannot accidentally compare a folded column against a raw term —
     * which would silently return nothing for every accented search.
     */
    public static function contains(string $haystack, string $needle): bool
    {
        $needle = self::fold($needle);

        return $needle === '' || str_contains(self::fold($haystack), $needle);
    }
}
