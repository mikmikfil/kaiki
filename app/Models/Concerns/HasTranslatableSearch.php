<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Contracts\TranslatableSearchable;
use App\Observers\SearchIndexObserver;
use App\Rules\TranslatableRequired;
use App\Support\Locale\LocaleResolver;
use App\Support\Locale\TranslationColumns;
use App\Support\Locale\TranslationValue;
use App\Support\Text\GreekText;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Searching and ordering translatable text without ever touching a JSON path.
 *
 * Spec CAT-6 / ENV-8 / I18N-4 forbid `orderBy` and `where` on a JSON path,
 * because MySQL and SQLite disagree about both — `utf8mb4_unicode_ci` folds
 * tonos, SQLite's `NOCASE` folds nothing outside ASCII — and a search that
 * returns different rows locally than in production is worse than no search.
 *
 * The fix, per ADR-0008, is to keep plain columns beside the JSON and fill them
 * from PHP on save: one `search_index` haystack per row, and one
 * `{attribute}_sort_{locale}` key per orderable field. Both sides of every
 * comparison then go through {@see GreekText}, so the database is only ever
 * asked to compare bytes this application produced.
 *
 * ## Adopting it
 *
 * ```php
 * final class Product extends Model implements TranslatableSearchable
 * {
 *     use HasKaikiTranslations;
 *     use HasTranslatableSearch;
 *
 *     public array $translatable = ['title', 'summary'];
 *     protected array $translatableSearch = ['title', 'summary'];
 *     protected array $translatableSort = ['title'];
 *     protected array $requiredTranslations = ['title'];
 *
 *     // Plain columns that still need folding — see below.
 *     protected array $foldedSearch = ['name'];
 *     protected array $foldedSort = ['name'];
 * }
 * ```
 *
 * That is the whole per-model cost. The observer is registered by this trait's
 * boot method, so no model ever wires one up itself and none can forget to.
 *
 * ## Plain columns
 *
 * Not every column that needs folding is JSON. `vessels.name` is a proper noun
 * and deliberately *not* translatable (`docs/data-model.md` §1.6), yet a plain
 * `orderBy name` and `where name like` diverge between the engines for exactly
 * the reason above — MySQL folds Greek tonos when comparing, SQLite does not,
 * so `οδυσσευς` finds `Οδυσσεύς` in production and misses it locally.
 *
 * The divergence is a property of *Greek text*, not of JSON, so `$foldedSearch`
 * and `$foldedSort` extend the same machinery to plain columns: they feed the
 * same `search_index` haystack and get a single `{attribute}_sort` companion —
 * single, not per-locale, because a proper noun has one form in both languages.
 *
 * The lists are read with `property_exists` rather than declared here, because
 * PHP forbids a class redeclaring a trait property with a different default —
 * declaring `$translatableSearch = []` in the trait would make
 * `$translatableSearch = ['title']` in the model a fatal error.
 */
trait HasTranslatableSearch
{
    /**
     * How much of a folded value is kept as a sort key.
     *
     * Sort columns are indexed, and MySQL cannot index an unbounded string.
     * 191 is the familiar `utf8mb4` limit under a 767-byte index prefix; no
     * vessel or product title comes close, and ordering by the first 191
     * characters is indistinguishable from ordering by all of them.
     */
    public const SORT_VALUE_LENGTH = 191;

    public static function bootHasTranslatableSearch(): void
    {
        static::observe(SearchIndexObserver::class);
    }

    /** @return list<string> */
    public function translatableSearchAttributes(): array
    {
        return $this->declaredAttributeList('translatableSearch');
    }

    /** @return list<string> */
    public function translatableSortAttributes(): array
    {
        return $this->declaredAttributeList('translatableSort');
    }

    /** @return list<string> */
    public function requiredTranslationAttributes(): array
    {
        return $this->declaredAttributeList('requiredTranslations');
    }

    /** @return list<string> */
    public function foldedSearchAttributes(): array
    {
        return $this->declaredAttributeList('foldedSearch');
    }

    /** @return list<string> */
    public function foldedSortAttributes(): array
    {
        return $this->declaredAttributeList('foldedSort');
    }

    /** @return list<string> */
    public function translationSearchValues(): array
    {
        $values = [];

        foreach ($this->translatableSearchAttributes() as $attribute) {
            foreach ($this->getTranslations($attribute) as $translation) {
                foreach (TranslationValue::strings($translation) as $string) {
                    $values[] = $string;
                }
            }
        }

        return $values;
    }

    /**
     * The plain attributes' values, ready to join the same haystack.
     *
     * Read through `getAttribute()` rather than `$this->{$attribute}` so a
     * column with a cast arrives as whatever the cast makes of it and is then
     * filtered to strings by {@see TranslationValue::strings()}, instead of
     * fataling on a `BackedEnum` where a string was expected.
     *
     * @return list<string>
     */
    public function foldedSearchValues(): array
    {
        $values = [];

        foreach ($this->foldedSearchAttributes() as $attribute) {
            foreach (TranslationValue::strings($this->getAttribute($attribute)) as $string) {
                $values[] = $string;
            }
        }

        return $values;
    }

    /**
     * The ordering key for one plain attribute.
     *
     * Folded and truncated exactly as {@see self::translationSortValue()} does.
     * The two feed different columns but the same kind of `ORDER BY`, and a
     * pair of sort keys built to different conventions is a list that looks
     * sorted until the first accented row.
     */
    public function foldedSortValue(string $attribute): string
    {
        $value = implode(' ', TranslationValue::strings($this->getAttribute($attribute)));

        $folded = GreekText::fold($value);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $folded)), 0, self::SORT_VALUE_LENGTH);
    }

    /**
     * The ordering key for one attribute in one locale.
     *
     * Deliberately built from the **fallback-resolved** value rather than the
     * raw one: the list is ordered by what the reader can see, and a product
     * with no English title renders its Greek one. Sorting it under an empty
     * key would bury it at one end of a list it visibly belongs in the middle
     * of.
     */
    public function translationSortValue(string $attribute, string $locale): string
    {
        /** @var mixed $value */
        $value = $this->getTranslation($attribute, $locale);

        $folded = GreekText::fold(implode(' ', TranslationValue::strings($value)));

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $folded)), 0, self::SORT_VALUE_LENGTH);
    }

    /**
     * Required locales this attribute has no usable translation for.
     *
     * Delegated rather than decided here, so this and
     * {@see TranslatableRequired} cannot answer differently. They
     * did while this compared key sets: `getTranslations()` drops null and
     * empty-string entries but keeps `"   "`, so an operator who tabbed past
     * the English field was refused by the form and accepted by the observer —
     * and the import, which never sees a form, was therefore the only writer
     * that could get the value in.
     *
     * @return list<string>
     */
    public function missingTranslationLocales(string $attribute): array
    {
        return TranslationValue::missingLocales(
            $this->getTranslations($attribute),
            LocaleResolver::required(),
        );
    }

    /**
     * Rows whose text matches `$term` in any locale, accent- and sigma-blind.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereTranslationMatches(Builder $query, ?string $term): void
    {
        $needle = self::searchNeedle($term);

        // An empty search box shows everything rather than nothing — the same
        // rule GreekText::contains() applies, so the two agree.
        if ($needle === null) {
            return;
        }

        $query->where(
            $query->qualifyColumn(TranslationColumns::SEARCH),
            'like',
            "%{$needle}%",
        );
    }

    /**
     * Order by a translatable attribute, through its companion column.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOrderByTranslation(
        Builder $query,
        string $attribute,
        string $direction = 'asc',
        ?string $locale = null,
    ): void {
        $locale ??= (string) app()->getLocale();

        // Both halves are validated against declared lists rather than escaped,
        // because they become a column name — and a column name cannot be
        // bound as a parameter. Filament passes the sort column straight from
        // the query string, so "it is only ever called with a constant" stops
        // being true the first time this is wired to a table header.
        if (! in_array($attribute, $this->translatableSortAttributes(), true)) {
            throw new InvalidArgumentException(
                "[{$attribute}] is not a sortable translatable attribute on " . static::class
                . '. Add it to $translatableSort and create its companion columns.',
            );
        }

        if (! in_array($locale, LocaleResolver::installed(), true)) {
            throw new InvalidArgumentException(
                "[{$locale}] is not an installed locale, so it has no sort column.",
            );
        }

        $query->orderBy(
            $query->qualifyColumn(TranslationColumns::sort($attribute, $locale)),
            strtolower($direction) === 'desc' ? 'desc' : 'asc',
        );
    }

    /**
     * Order by a plain folded attribute, through its companion column.
     *
     * Separate from {@see self::scopeOrderByTranslation()} rather than a flag
     * on it, because the column names differ in shape — `name_sort` against
     * `title_sort_el` — and a single method taking a nullable locale would have
     * to guess which kind it was handed. Guessing wrong produces a column name
     * that does not exist, which surfaces as a SQL error on a table header
     * click rather than as anything a reader could predict.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOrderByFolded(Builder $query, string $attribute, string $direction = 'asc'): void
    {
        // Validated against the declared list rather than escaped, for the same
        // reason as the translatable scope: this becomes a column name, a
        // column name cannot be bound as a parameter, and Filament passes the
        // sort column straight from the query string.
        if (! in_array($attribute, $this->foldedSortAttributes(), true)) {
            throw new InvalidArgumentException(
                "[{$attribute}] is not a sortable folded attribute on " . static::class
                . '. Add it to $foldedSort and create its companion column.',
            );
        }

        $query->orderBy(
            $query->qualifyColumn(TranslationColumns::foldedSort($attribute)),
            strtolower($direction) === 'desc' ? 'desc' : 'asc',
        );
    }

    /**
     * A search term reduced to the same form as the stored haystack.
     *
     * `%` and `_` are **stripped rather than escaped**. Escaping needs a
     * trailing `ESCAPE` clause to mean anything on SQLite while MySQL assumes
     * one, and ENV-12 forbids branching on the driver to change behaviour.
     * Dropping the two characters costs a literal-underscore search nobody
     * performs, and keeps one code path for both engines.
     */
    public static function searchNeedle(?string $term): ?string
    {
        if ($term === null) {
            return null;
        }

        $folded = str_replace(['%', '_'], '', GreekText::fold($term));
        $folded = trim((string) preg_replace('/\s+/u', ' ', $folded));

        return $folded === '' ? null : $folded;
    }

    /**
     * @return list<string>
     */
    private function declaredAttributeList(string $property): array
    {
        if (! property_exists($this, $property)) {
            return [];
        }

        /** @var mixed $value */
        $value = $this->{$property};

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
