<?php

declare(strict_types=1);

use App\Support\Locale\TranslationColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Translatable\TranslatableFixture;

/**
 * The table behind {@see TranslatableFixture}.
 *
 * It lives under `tests/` and **must never move to `database/migrations`** — a
 * fixture table in production is a table nobody can explain in a year.
 * `Tests\TestCase` registers this directory with the migrator, so it is created
 * by the same `RefreshDatabase` run as everything else, on SQLite locally and
 * MySQL 8 in CI. Creating it with `Schema::create()` inside a test instead
 * would be DDL inside `RefreshDatabase`'s transaction: harmless on SQLite,
 * an implicit commit on MySQL, and therefore a leak that only ever appears in
 * the CI job.
 *
 * The columns are the ones ADR-0008 requires beside translatable JSON, named by
 * {@see TranslationColumns} rather than typed out — which is the same call a
 * real catalogue migration makes, so this fixture proves that path too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translatable_fixtures', function (Blueprint $table): void {
            $table->id();

            // Translatable JSON, exactly as §1.6 describes: `{"el": …, "en": …}`.
            // `includes` is the translatable *array* case.
            $table->json('title');
            $table->json('summary')->nullable();
            $table->json('includes')->nullable();

            // The per-row haystack. `text`, not `string`: it holds every locale
            // of every searchable field concatenated, and a product's summary
            // alone runs past 255 characters routinely.
            //
            // **Deliberately not indexed**, unlike the sort columns below, and
            // the reason is worth stating because issue #15 asks for "a plain
            // indexed column".
            //
            // Two things rule an index out, and they compound. MySQL 8 refuses
            // an index on a `TEXT` column without a key length — `$table->index()`
            // here is an error, not a slow query — while SQLite has no prefix
            // indexes at all, so there is no one declaration that runs on both
            // engines (ENV-12). And a prefix index would buy nothing anyway:
            // the search is `LIKE '%term%'`, and a leading wildcard cannot use
            // a B-tree.
            //
            // The portable index that would help is `FULLTEXT`, which
            // `docs/data-model.md` §0 forbids outright for the same
            // both-engines reason. So catalogue search is a tenant-scoped scan
            // over tens to hundreds of rows, which is what CAT-6 describes; the
            // answer at real volume is the search backend ADR-0008 already
            // parks as a future ADR, not an index here.
            $table->text(TranslationColumns::SEARCH)->nullable();

            // One ordering key per locale, indexed — the index is the whole
            // justification for the column existing at all. 191 characters is
            // what HasTranslatableSearch truncates to, and the familiar utf8mb4
            // limit under a 767-byte index prefix.
            //
            // Derived from the installed locales rather than listed, so adding
            // a locale to `config('app.available_locales')` and forgetting the
            // column here is impossible.
            foreach (TranslationColumns::sortColumnsFor(['title']) as $column) {
                $table->string($column, 191)->nullable()->index();
            }

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translatable_fixtures');
    }
};
