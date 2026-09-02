<?php

declare(strict_types=1);

namespace App\Observers;

use App\Console\Commands\BackfillTranslationsCommand;
use App\Exceptions\MissingTranslationException;
use App\Models\Concerns\HasTranslatableSearch;
use App\Models\Contracts\TranslatableSearchable;
use App\Support\Locale\LocaleResolver;
use App\Support\Locale\TranslationColumns;
use App\Support\Text\GreekText;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the companion columns ADR-0008 requires in step with the JSON.
 *
 * Translatable text is a JSON column and **no query may sort or filter on a
 * JSON path** (CAT-6, ENV-8, I18N-4). Ordering and searching therefore run
 * against plain indexed columns — one `search_index` haystack per row, one
 * `{attribute}_sort_{locale}` key per orderable field — and those columns are
 * only ever correct because this observer rewrites them on every save.
 *
 * {@see HasTranslatableSearch} registers it, so no model wires one up by hand
 * and none can forget to.
 *
 * ## Why `saving` and not `saved`
 *
 * The columns are set on the model *before* the INSERT or UPDATE is built, so
 * they travel in the same statement. On `saved` they would need a second write
 * — which doubles the statements on every catalogue edit, and leaves a window
 * inside a transaction where the row exists with a stale or empty
 * `search_index`. A concurrent read in that window returns a product that
 * cannot be found by its own name.
 *
 * ## Why it writes unconditionally rather than on `isDirty()`
 *
 * A sort key is built from the **fallback-resolved** value, so it depends on
 * the tenant's `default_locale` as well as on the JSON — an operator switching
 * their house language changes every sort key in the catalogue without touching
 * a single product. Rebuilding costs one `mb_strtolower` and a `strtr` per
 * field, against a database round trip that is already happening.
 */
final class SearchIndexObserver
{
    public function saving(Model&TranslatableSearchable $model): void
    {
        self::assertRequiredTranslations($model);

        self::rebuild($model);
    }

    /**
     * Recompute every companion column, and report whether anything moved.
     *
     * Public and separate from {@see self::saving()} because
     * {@see BackfillTranslationsCommand} needs exactly this and none of the
     * rest: a backfill runs over rows that already exist, some of which predate
     * the requirement it would otherwise refuse to save them under. Repairing a
     * search index is not the moment to discover an import from 2025 has no
     * English summary — the command reports those, it does not crash on them.
     *
     * @return bool true when at least one column changed
     */
    public static function rebuild(Model&TranslatableSearchable $model): bool
    {
        $changed = false;

        // A model that declares no searchable attributes has no `search_index`
        // column to write to. Writing one anyway would add an attribute that is
        // not a column and fail the INSERT — which is how "I only wanted
        // sorting" turns into an unrelated SQL error.
        if ($model->translatableSearchAttributes() !== []) {
            $index = GreekText::searchIndex($model->translationSearchValues());

            if (self::set($model, TranslationColumns::SEARCH, $index)) {
                $changed = true;
            }
        }

        foreach ($model->translatableSortAttributes() as $attribute) {
            // The **installed** locales, deliberately not the required ones:
            // these are columns, and a column exists because a migration made
            // it. `TranslationColumns::sortColumnsFor()` defaults to the same
            // list, so the migration and this loop cannot drift apart.
            foreach (LocaleResolver::installed() as $locale) {
                $column = TranslationColumns::sort($attribute, $locale);

                if (self::set($model, $column, $model->translationSortValue($attribute, $locale))) {
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    /**
     * Every required locale missing from every required attribute.
     *
     * @return array<string, list<string>> attribute => missing locales
     */
    public static function missingTranslations(Model&TranslatableSearchable $model): array
    {
        $missing = [];

        foreach ($model->requiredTranslationAttributes() as $attribute) {
            $locales = $model->missingTranslationLocales($attribute);

            if ($locales !== []) {
                $missing[$attribute] = $locales;
            }
        }

        return $missing;
    }

    /**
     * `docs/data-model.md` §1.6, enforced rather than documented.
     *
     * Only the **first** offending attribute is named. A save is refused
     * outright, so listing all of them helps nobody: the caller is a form that
     * has already validated (and therefore should never arrive here), or an
     * import that needs one clear reason in its error log.
     */
    private static function assertRequiredTranslations(Model&TranslatableSearchable $model): void
    {
        foreach (self::missingTranslations($model) as $attribute => $locales) {
            throw MissingTranslationException::forAttribute($model::class, $attribute, $locales);
        }
    }

    /**
     * Set one column, reporting whether it actually differed.
     *
     * The comparison is what makes {@see self::rebuild()} usable as a drift
     * *check* — `--dry-run` on the backfill counts rows this returns true for,
     * and a repair that reports "0 rows needed fixing" is the only evidence the
     * observer has been doing its job all along.
     */
    private static function set(Model&TranslatableSearchable $model, string $column, string $value): bool
    {
        if ($model->getAttribute($column) === $value) {
            return false;
        }

        $model->setAttribute($column, $value);

        return true;
    }
}
