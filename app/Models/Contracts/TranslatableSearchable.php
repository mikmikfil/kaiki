<?php

declare(strict_types=1);

namespace App\Models\Contracts;

use App\Models\Concerns\HasTranslatableSearch;
use App\Observers\SearchIndexObserver;
use App\Support\Locale\TranslationColumns;

/**
 * A model whose translatable text is searchable, sortable, or required in more
 * than one locale.
 *
 * {@see HasTranslatableSearch} implements every method here, so adopting this
 * is one `implements` and one or more attribute lists — no per-model observer
 * code, which is the point of the whole arrangement (issue #15, AC 8).
 *
 * The interface exists so {@see SearchIndexObserver} can be typed rather than
 * probing for methods with `method_exists`. It also states the dependency the
 * trait cannot: a searchable model is necessarily a *translatable* one, which
 * is why `getTranslations()` is redeclared here.
 */
interface TranslatableSearchable
{
    /**
     * The per-row search haystack.
     *
     * Named here rather than only in {@see TranslationColumns}
     * because that class refers back to this constant — the value has exactly
     * one definition, and this is it.
     */
    public const SEARCH_COLUMN = 'search_index';

    /**
     * Translatable attributes that feed the `search_index` column.
     *
     * @return list<string>
     */
    public function translatableSearchAttributes(): array;

    /**
     * Translatable attributes that need a per-locale ordering column.
     *
     * @return list<string>
     */
    public function translatableSortAttributes(): array;

    /**
     * Translatable attributes that must be present in every required locale.
     *
     * @return list<string>
     */
    public function requiredTranslationAttributes(): array;

    /**
     * Every translation of every searchable attribute, flattened to strings.
     *
     * Flattened because `products.includes` and friends are translatable
     * *arrays* (`docs/data-model.md` §3.5) — a guest searching for something
     * mentioned in the inclusions list must still find the product.
     *
     * @return list<string>
     */
    public function translationSearchValues(): array;

    /**
     * The ordering key for one attribute in one locale.
     */
    public function translationSortValue(string $attribute, string $locale): string;

    /**
     * Required locales this attribute has no usable translation for.
     *
     * @return list<string>
     */
    public function missingTranslationLocales(string $attribute): array;

    /**
     * From `spatie/laravel-translatable`.
     *
     * @param  list<string>|null  $allowedLocales
     * @return array<string, mixed>
     */
    public function getTranslations(?string $key = null, ?array $allowedLocales = null): array;
}
