<?php

declare(strict_types=1);

namespace App\Support\Locale;

use App\Models\Contracts\TranslatableSearchable;

/**
 * The names of the companion columns ADR-0008 requires.
 *
 * Translatable text is a JSON column, and **no query may sort or filter on a
 * JSON path** (spec CAT-6, ENV-8). Every translatable field that needs
 * ordering or searching therefore gets a plain, indexed column that an observer
 * fills on save. This class is the single place that decides what those columns
 * are called.
 *
 * It is a support class rather than a method on the model trait because the
 * *migrations* need the same answer, and a migration has no model instance. The
 * alternative — `products.title_sort_el` typed out in a migration, in a
 * Filament resource, in the observer and in a test — is four chances to typo a
 * column name into a silently empty sort order.
 */
final class TranslationColumns
{
    /**
     * The per-row haystack: every locale of every searchable field, folded and
     * concatenated (ADR-0008).
     *
     * One column rather than one per locale, because a guest searching a
     * bilingual catalogue does not know or care which language a product was
     * written in — they type `καΐκι` or `caique` and expect the same boat.
     */
    public const SEARCH = TranslatableSearchable::SEARCH_COLUMN;

    /**
     * The per-locale ordering key for one translatable attribute.
     *
     * Per locale, unlike {@see self::SEARCH}, because ordering *is* a
     * per-language question: a Greek product list must read alphabetically in
     * Greek, and the same list in English must read alphabetically in English.
     */
    public static function sort(string $attribute, string $locale): string
    {
        return "{$attribute}_sort_{$locale}";
    }

    /**
     * The ordering key for a **plain** (non-translatable) column.
     *
     * One column, not one per locale, because the value it folds has one form:
     * `vessels.name` is a proper noun (`docs/data-model.md` §1.6) and reads the
     * same in Greek and in English. Giving it `name_sort_el` and `name_sort_en`
     * would be two columns that are always byte-identical.
     *
     * It exists at all because a plain `orderBy`/`LIKE` on the raw column is
     * not portable: MySQL's `utf8mb4_unicode_ci` folds Greek tonos and SQLite's
     * `BINARY` folds nothing, so searching `οδυσσευς` finds `Οδυσσεύς` in
     * production and misses it locally. That is the same divergence ADR-0008
     * built the translatable companions for, and it does not care whether the
     * column happens to be JSON.
     */
    public static function foldedSort(string $attribute): string
    {
        return "{$attribute}_sort";
    }

    /**
     * Every sort column a model needs, for a migration to create.
     *
     * @param  list<string>  $attributes
     * @param  list<string>|null  $locales  defaults to the installed locales
     * @return list<string>
     */
    public static function sortColumnsFor(array $attributes, ?array $locales = null): array
    {
        $locales ??= LocaleResolver::installed();
        $columns = [];

        foreach ($attributes as $attribute) {
            foreach ($locales as $locale) {
                $columns[] = self::sort($attribute, $locale);
            }
        }

        return $columns;
    }
}
