<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Locale\TranslationColumns;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\artisan;

use Tests\Support\Translatable\TranslatableFixture;

/*
 * ADR-0008: "a backfill command exists for imports".
 *
 * The companion columns are only correct because an observer maintains them,
 * and three ordinary things go around that observer: a bulk `insert()`, a
 * `saveQuietly()` in an importer, and a migration that adds a column PHP has to
 * fill. None of them is a bug, and all of them leave a boat that is on the list,
 * bookable, and impossible to find by name.
 */

beforeEach(function (): void {
    // The command runs inside each tenant, because every translatable table in
    // data-model §1.6 is tenant-owned and a sort key is resolved against the
    // tenant's `default_locale`.
    Tenant::factory()->create(['default_locale' => 'el']);
});

/**
 * Write straight past the observer, the way an importer does.
 */
function staleFixture(): TranslatableFixture
{
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
    ]);

    DB::table('translatable_fixtures')->where('id', $fixture->getKey())->update([
        TranslationColumns::SEARCH => '',
        TranslationColumns::sort('title', 'el') => '',
        TranslationColumns::sort('title', 'en') => '',
    ]);

    return $fixture;
}

it('rebuilds columns that were written around the observer', function (): void {
    $fixture = staleFixture();

    artisan('kaiki:backfill-translations', ['--model' => [TranslatableFixture::class]])
        ->assertSuccessful();

    $repaired = $fixture->fresh();

    expect($repaired?->getAttribute(TranslationColumns::SEARCH))->toContain('αιγινα')
        ->and($repaired?->getAttribute(TranslationColumns::sort('title', 'en')))->toBe('aegina');
})->group('fast', 'i18n');

it('reports no drift and writes nothing when everything is current', function (): void {
    TranslatableFixture::create(['title' => ['el' => 'Ύδρα', 'en' => 'Hydra']]);

    artisan('kaiki:backfill-translations', ['--model' => [TranslatableFixture::class], '--dry-run' => true])
        ->assertSuccessful();
})->group('fast', 'i18n');

it('fails a dry run when a row would change, and changes nothing', function (): void {
    $fixture = staleFixture();

    // `--dry-run` is a drift **check**, not a preview: it exits 1 so the
    // nightly workflow can gate on it. An observer that has quietly stopped
    // keeping up is otherwise completely silent.
    artisan('kaiki:backfill-translations', ['--model' => [TranslatableFixture::class], '--dry-run' => true])
        ->assertExitCode(1);

    expect($fixture->fresh()?->getAttribute(TranslationColumns::SEARCH))->toBe('');
})->group('fast', 'i18n');

it('does not touch updated_at when it repairs a row', function (): void {
    $fixture = staleFixture();

    DB::table('translatable_fixtures')
        ->where('id', $fixture->getKey())
        ->update(['updated_at' => '2026-01-01 00:00:00']);

    artisan('kaiki:backfill-translations', ['--model' => [TranslatableFixture::class]])
        ->assertSuccessful();

    // Repairing a derived column is not an edit. Bumping `updated_at` on every
    // product in a catalogue would show up as the operator's own change in
    // every "recently modified" list and in every future audit trail.
    expect($fixture->fresh()?->updated_at?->format('Y-m-d'))->toBe('2026-01-01');
})->group('fast', 'i18n');

it('reports an incomplete translation rather than crashing on it', function (): void {
    // A row that predates the requirement — exactly what a backfill is run
    // over. Refusing to repair its search index because its English title is
    // missing would leave it both untranslated *and* unfindable.
    DB::table('translatable_fixtures')->insert([
        'title' => json_encode(['el' => 'Αίγινα'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    artisan('kaiki:backfill-translations', ['--model' => [TranslatableFixture::class]])
        ->expectsOutputToContain('has no en translation')
        ->assertSuccessful();

    expect(DB::table('translatable_fixtures')->value(TranslationColumns::SEARCH))->toContain('αιγινα');
})->group('fast', 'i18n');

it('refuses a class that is not a translatable-searchable model', function (): void {
    // Named explicitly and wrong is a typo worth saying out loud. Backfilling
    // nothing in silence is how someone concludes the data was already fine.
    artisan('kaiki:backfill-translations', ['--model' => [Tenant::class]])
        ->expectsOutputToContain('is not a translatable-searchable model')
        ->assertSuccessful();
})->group('fast', 'i18n');

it('finds nothing to do when no model implements the contract', function (): void {
    // Discovery over `app/Models`, which today contains no translatable model
    // at all — `Product`, `Vessel` and the rest are #16 and later. The command
    // has to say so rather than report a successful backfill of nothing.
    artisan('kaiki:backfill-translations')
        ->expectsOutputToContain('No translatable-searchable models found')
        ->assertSuccessful();
})->group('fast', 'i18n');
