<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Observers\SearchIndexObserver;
use App\Support\Locale\TranslationColumns;
use App\Support\Tenancy;
use Tests\Support\Translatable\TranslatableFixture;

/*
 * Spec CAT-6 / ENV-8: ordering translatable text goes through a per-locale
 * companion column, never a JSON path.
 *
 * Per locale, unlike the single shared `search_index`, because ordering *is* a
 * per-language question. A Greek product list must read alphabetically in
 * Greek and the same list in English must read alphabetically in English —
 * they are different orders over the same rows, and one column cannot hold
 * both.
 */

it('writes one sort key per installed locale', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
    ]);

    expect($fixture->getAttribute(TranslationColumns::sort('title', 'el')))->toBe('αιγινα')
        ->and($fixture->getAttribute(TranslationColumns::sort('title', 'en')))->toBe('aegina');
})->group('fast', 'i18n');

it('orders by the locale it was asked for, not the current one', function (): void {
    // Chosen so the two orders genuinely disagree. In Greek: Αίγινα, Ύδρα.
    // In English: Hydra, Aegina. A test where both languages happen to sort
    // the same way proves nothing at all.
    TranslatableFixture::create(['title' => ['el' => 'Αίγινα', 'en' => 'Hydra']]);
    TranslatableFixture::create(['title' => ['el' => 'Ύδρα', 'en' => 'Aegina']]);

    $greek = TranslatableFixture::query()
        ->orderByTranslation('title', 'asc', 'el')
        ->pluck(TranslationColumns::sort('title', 'el'))
        ->all();

    $english = TranslatableFixture::query()
        ->orderByTranslation('title', 'asc', 'en')
        ->pluck(TranslationColumns::sort('title', 'en'))
        ->all();

    expect($greek)->toBe(['αιγινα', 'υδρα'])
        ->and($english)->toBe(['aegina', 'hydra']);
})->group('fast', 'i18n');

it('falls back to the application locale when none is given', function (): void {
    TranslatableFixture::create(['title' => ['el' => 'Βήτα', 'en' => 'Alpha']]);
    TranslatableFixture::create(['title' => ['el' => 'Άλφα', 'en' => 'Beta']]);

    app()->setLocale('el');

    $titles = TranslatableFixture::query()
        ->orderByTranslation('title')
        ->pluck(TranslationColumns::sort('title', 'el'))
        ->all();

    expect($titles)->toBe(['αλφα', 'βητα']);
})->group('fast', 'i18n');

it('orders descending when asked, and treats anything else as ascending', function (): void {
    TranslatableFixture::create(['title' => ['el' => 'Άλφα', 'en' => 'Alpha']]);
    TranslatableFixture::create(['title' => ['el' => 'Βήτα', 'en' => 'Beta']]);

    $column = TranslationColumns::sort('title', 'en');

    expect(TranslatableFixture::query()->orderByTranslation('title', 'DESC', 'en')->pluck($column)->all())
        ->toBe(['beta', 'alpha'])
        // A direction from a query string is whatever the browser sent. The
        // only two safe answers are "desc" and "ascending"; interpolating an
        // unknown value into SQL is not one of them.
        ->and(TranslatableFixture::query()->orderByTranslation('title', 'sideways', 'en')->pluck($column)->all())
        ->toBe(['alpha', 'beta']);
})->group('fast', 'i18n');

it('refuses to order by an attribute that has no sort column', function (): void {
    // `summary` is searchable but not sortable, so no `summary_sort_*` column
    // exists. Filament passes the sort column straight from the query string,
    // so "it is only ever called with a constant" stops being true the moment
    // this is wired to a table header — and a column name cannot be bound as a
    // parameter, so validation against the declared list is the whole defence.
    TranslatableFixture::query()->orderByTranslation('summary');
})->throws(InvalidArgumentException::class, 'not a sortable translatable attribute')
    ->group('fast', 'i18n');

it('refuses to order by a locale that is not installed', function (): void {
    TranslatableFixture::query()->orderByTranslation('title', 'asc', 'de');
})->throws(InvalidArgumentException::class, 'not an installed locale')
    ->group('fast', 'i18n');

it('builds the sort key from the visible value, not the raw one', function (): void {
    $tenant = Tenant::factory()->create(['default_locale' => 'el']);

    Tenancy::forTenant($tenant, function (): void {
        $fixture = TranslatableFixture::create([
            'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
        ]);

        // A row with no English title cannot be *saved* — `title` is required
        // in both locales and the observer refuses it — but it can certainly
        // exist, because an import wrote it before the rule did, or wrote it
        // with `saveQuietly()`. That is exactly the row the backfill is run
        // over, so the key is recomputed here the way the backfill does it.
        $fixture->forgetTranslation('title', 'en');

        SearchIndexObserver::rebuild($fixture);

        // I18N-5 is *requested locale, then the tenant's `default_locale`, then
        // `en`* — so for this Greek operator the English list renders the Greek
        // title, and the row must sort where a reader can see it, under `α`.
        // An empty key would bury it at one end of a list it visibly belongs in
        // the middle of.
        expect($fixture->getAttribute(TranslationColumns::sort('title', 'en')))->toBe('αιγινα');
    });
})->group('fast', 'i18n');

it('leaves the sort key empty when no locale in the chain has a value', function (): void {
    // The same row without a tenant. I18N-5's chain is requested, tenant
    // default, `en` — it is deliberately **not** "any locale that happens to
    // have something in it". With no tenant resolved there is nothing after
    // `en`, and inventing a fallback to Greek here would mean the panel and the
    // public API disagreed about what a product is called depending on how the
    // request arrived.
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
    ]);

    $fixture->forgetTranslation('title', 'en');

    SearchIndexObserver::rebuild($fixture);

    expect($fixture->getAttribute(TranslationColumns::sort('title', 'en')))->toBe('');
})->group('fast', 'i18n');

it('truncates a sort key to an indexable length', function (): void {
    $long = str_repeat('α', 400);

    $fixture = TranslatableFixture::create([
        'title' => ['el' => $long, 'en' => $long],
    ]);

    // MySQL cannot index an unbounded string. Ordering by the first 191
    // characters is indistinguishable from ordering by all of them for any
    // title a person would type.
    expect(mb_strlen((string) $fixture->getAttribute(TranslationColumns::sort('title', 'el'))))
        ->toBe(TranslatableFixture::SORT_VALUE_LENGTH);
})->group('fast', 'i18n');

it('collapses whitespace in a sort key', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => '  Αίγινα   Μαρίνα  ', 'en' => '  Aegina   Marina  '],
    ]);

    // A name pasted out of a spreadsheet with a double space must sort beside
    // the same name typed by hand, not between two unrelated ones.
    expect($fixture->getAttribute(TranslationColumns::sort('title', 'en')))->toBe('aegina marina');
})->group('fast', 'i18n');
