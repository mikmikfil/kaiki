<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contracts\TranslatableSearchable;
use App\Models\Tenant;
use App\Observers\SearchIndexObserver;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Rebuild the `search_index` and `*_sort_{locale}` columns from the JSON.
 *
 * ADR-0008 requires this in as many words: *"a backfill command exists for
 * imports"*. Three things make the companion columns go stale, and none of them
 * is a bug in {@see SearchIndexObserver}:
 *
 *   1. **An import** that wrote rows with `saveQuietly()` or a bulk `insert()`,
 *      neither of which fires an observer.
 *   2. **A new locale.** Shipping German lang files adds `title_sort_de` to
 *      every catalogue table, and the migration that adds the column cannot
 *      fill it — only PHP knows how to fold the text.
 *   3. **A new sortable field.** Adding `summary` to `$translatableSort` makes
 *      two empty columns that stay empty until every row is saved again.
 *
 * ## `--dry-run` is a drift check, not a preview
 *
 * It exits **1** when any row would change, so it can run in the nightly
 * workflow as a gate: a non-zero exit means the observer has stopped keeping up
 * with the JSON, which is the failure this whole arrangement exists to prevent
 * and is otherwise completely silent — a product simply stops appearing in
 * search results, and nobody files a bug about a boat they cannot see.
 *
 * ## Why it runs per tenant
 *
 * Every translatable table in `docs/data-model.md` §1.6 is tenant-owned, and a
 * sort key is built from the **fallback-resolved** value, which consults the
 * tenant's `default_locale`. Run outside tenant context, `BelongsToTenant`
 * would hand back every operator's rows and resolve all of them against `en` —
 * quietly rewriting a Greek operator's sort keys with English text.
 */
final class BackfillTranslationsCommand extends Command
{
    protected $signature = 'kaiki:backfill-translations
        {--model=* : Fully-qualified model class; repeatable. Defaults to every translatable-searchable model.}
        {--tenant=* : Tenant id or slug; repeatable. Defaults to every tenant.}
        {--chunk=200 : Rows per query.}
        {--dry-run : Report what would change and exit 1 if anything would, writing nothing.}';

    protected $description = 'Rebuild the search and sort companion columns for translatable models (ADR-0008).';

    public function handle(): int
    {
        $models = $this->models();

        if ($models === []) {
            $this->components->warn('No translatable-searchable models found. Nothing to backfill.');

            return self::SUCCESS;
        }

        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->components->warn('No tenants found. Nothing to backfill.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $drifted = 0;

        foreach ($tenants as $tenant) {
            foreach ($models as $model) {
                /** @var array{scanned: int, repaired: int, incomplete: int} $result */
                $result = Tenancy::forTenant($tenant, fn (): array => $this->backfill($model, $dryRun));

                $drifted += $result['repaired'];

                // A model with nothing in it for this tenant is noise in the
                // table, not information — a fleet operator with no extras
                // should not read six rows of zeroes to find the one that
                // matters.
                if ($result['scanned'] === 0) {
                    continue;
                }

                $rows[] = [
                    (string) $tenant->slug,
                    class_basename($model),
                    (string) $result['scanned'],
                    (string) $result['repaired'],
                    $result['incomplete'] === 0 ? '-' : (string) $result['incomplete'],
                ];
            }
        }

        $this->table(['Tenant', 'Model', 'Scanned', $dryRun ? 'Would fix' : 'Repaired', 'Incomplete'], $rows);

        if ($dryRun && $drifted > 0) {
            $this->components->error("{$drifted} row(s) have stale search or sort columns.");

            return self::FAILURE;
        }

        $this->components->info($dryRun ? 'No drift.' : "Repaired {$drifted} row(s).");

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model&TranslatableSearchable>  $model
     * @return array{scanned: int, repaired: int, incomplete: int}
     */
    private function backfill(string $model, bool $dryRun): array
    {
        $scanned = 0;
        $repaired = 0;
        $incomplete = 0;

        $model::query()->chunkById(
            max(1, (int) $this->option('chunk')),
            function (EloquentCollection $records) use ($dryRun, &$scanned, &$repaired, &$incomplete): void {
                foreach ($records as $record) {
                    if (! $record instanceof TranslatableSearchable) {
                        continue;
                    }

                    $scanned++;

                    // Reported, never thrown. A row that predates the
                    // requirement is exactly what a backfill is run over, and
                    // refusing to repair its search index because its English
                    // summary is missing would leave it both untranslated *and*
                    // unfindable.
                    foreach (SearchIndexObserver::missingTranslations($record) as $attribute => $locales) {
                        $incomplete++;

                        $this->components->warn(sprintf(
                            '%s #%s: [%s] has no %s translation.',
                            class_basename($record),
                            (string) $record->getKey(),
                            $attribute,
                            implode('/', $locales),
                        ));
                    }

                    if (! SearchIndexObserver::rebuild($record)) {
                        continue;
                    }

                    $repaired++;

                    if ($dryRun) {
                        continue;
                    }

                    // `saveQuietly()` because the observer has already done its
                    // work above, and `timestamps = false` because repairing a
                    // derived column is not an edit. Bumping `updated_at` on
                    // every product in a catalogue would show up as an
                    // operator's own change in every "recently modified" list
                    // and in every future audit trail.
                    $record->timestamps = false;
                    $record->saveQuietly();
                }
            },
        );

        return ['scanned' => $scanned, 'repaired' => $repaired, 'incomplete' => $incomplete];
    }

    /**
     * The models to repair: whatever `--model` names, or everything discovered.
     *
     * @return list<class-string<Model&TranslatableSearchable>>
     */
    private function models(): array
    {
        /** @var list<string> $named */
        $named = array_values(array_filter((array) $this->option('model'), 'is_string'));

        $candidates = $named === [] ? $this->discover() : $named;

        $models = [];

        foreach ($candidates as $candidate) {
            if (! is_a($candidate, Model::class, true) || ! is_a($candidate, TranslatableSearchable::class, true)) {
                // Named explicitly and wrong is a typo worth saying out loud;
                // silently backfilling nothing is how someone concludes the
                // data was already fine.
                if ($named !== []) {
                    $this->components->error("[{$candidate}] is not a translatable-searchable model.");
                }

                continue;
            }

            /** @var class-string<Model&TranslatableSearchable> $candidate */
            $models[] = $candidate;
        }

        return $models;
    }

    /**
     * Every `App\Models` class that implements the contract.
     *
     * Discovery rather than a hand-maintained list, because the list would be
     * wrong the first time someone adds a model and forgets — and the symptom
     * is a table that is quietly never repaired.
     *
     * @return list<string>
     */
    private function discover(): array
    {
        $directory = app_path('Models');

        if (! is_dir($directory)) {
            return [];
        }

        $classes = [];

        foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
            $classes[] = $this->classFor($file);
        }

        sort($classes);

        return $classes;
    }

    private function classFor(SplFileInfo $file): string
    {
        $relative = Str::after((string) $file->getRealPath(), app_path('Models') . DIRECTORY_SEPARATOR);

        return 'App\\Models\\' . str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relative,
        );
    }

    /**
     * The tenants to run inside: whatever `--tenant` names, or all of them.
     *
     * Soft-deleted operators are included on purpose. A cancelled account's
     * data still exists until the GDPR purge, and the day it is restored its
     * catalogue must be searchable — a backfill that skipped it would leave a
     * gap nobody thinks to look for.
     *
     * @return Collection<int, Tenant>
     */
    private function tenants(): Collection
    {
        /** @var list<string> $named */
        $named = array_values(array_filter((array) $this->option('tenant'), 'is_string'));

        $query = Tenant::withTrashed();

        if ($named !== []) {
            $query->where(function (Builder $query) use ($named): void {
                $query->whereIn('slug', $named)->orWhereIn('id', $named);
            });
        }

        return $query->orderBy('id')->get();
    }
}
