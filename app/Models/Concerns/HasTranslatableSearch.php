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
 * }
 * ```
 *
 * That is the whole per-model cost. The observer is registered by this trait's
 * boot method, so no model ever wires one up itself and none can forget to.
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
