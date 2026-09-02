<?php

declare(strict_types=1);

use App\Support\Locale\TranslationColumns;
use Tests\Support\Translatable\TranslatableFixture;

/*
 * Spec CAT-6 / I18N-4 / I18N-7 and ADR-0008: translatable text is searched
 * through a plain, accent-folded `search_index` column that an observer keeps
 * in step with the JSON, and **never** through a JSON path.
 *
 * The reason the column exists at all is that MySQL and SQLite disagree about
 * Greek. `utf8mb4_unicode_ci` folds tonos; SQLite's `NOCASE` folds nothing
 * outside ASCII. A search implemented in SQL would therefore return different
 * boats locally than in production — and the local answer is the one a
 * developer trusts, so the divergence is discovered by an operator.
 *
 * Folding both sides in PHP means the database only ever compares bytes this
 * application produced. These tests assert that, on whichever engine they run.
 */

it('fills the search index on insert, in the same statement', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Ημερήσια κρουαζιέρα', 'en' => 'Full-day cruise'],
    ]);

    // Read straight back out of the database rather than off the model. If the
    // observer ran on `saved` instead of `saving` the in-memory object would
    // still look right and the row would be empty — which is exactly the bug
    // this asserts against, and it is invisible from the model.
    $stored = TranslatableFixture::query()
        ->whereKey($fixture->getKey())
        ->value(TranslationColumns::SEARCH);

    expect($stored)
        ->toContain('ημερησια κρουαζιερα')
        ->toContain('full-day cruise');
})->group('fast', 'i18n');

it('folds tonos and final sigma into the index', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Οδυσσεύς', 'en' => 'Odysseus'],
    ]);

    // Three transformations in one value: uppercase to lower, tonos stripped,
    // and the final sigma normalised. Greek writes the same letter differently
    // at the end of a word, so a guest typing `οδυσσευσ` must reach a vessel
    // stored as `Οδυσσεύς`.
    expect($fixture->getAttribute(TranslationColumns::SEARCH))->toContain('οδυσσευσ');
})->group('fast', 'i18n');

it('indexes every locale of every searchable attribute', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Αίγινα', 'en' => 'Aegina'],
        'summary' => ['el' => 'Μια μέρα στον Σαρωνικό', 'en' => 'A day in the Saronic gulf'],
    ]);

    $index = (string) $fixture->getAttribute(TranslationColumns::SEARCH);

    // One column, not one per locale: a guest searching a bilingual catalogue
    // does not know which language a product was written in.
    expect($index)
        ->toContain('αιγινα')
        ->toContain('aegina')
        ->toContain('σαρωνικο')
        ->toContain('saronic');
})->group('fast', 'i18n');

it('indexes a translatable array column, not the shape around it', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Κρουαζιέρα', 'en' => 'Cruise'],
        'includes' => [
            'el' => ['Γεύμα', 'Εξοπλισμός κολύμβησης'],
            'en' => ['Lunch', 'Snorkelling gear'],
        ],
    ]);

    // `products.includes` is `{"el": ["…"], "en": ["…"]}` (data-model §3.5), and
    // "does the trip include lunch" is a question guests ask by typing *lunch*
    // into a search box. A naive implode() over the translation would index the
    // locale keys instead of the values.
    $index = (string) $fixture->getAttribute(TranslationColumns::SEARCH);

    expect($index)->toContain('lunch')->toContain('γευμα');

    // The values, not the shape around them: a naive implode() over the
    // translation set indexes the locale keys instead.
    expect($index)->not->toContain('"el"');
})->group('fast', 'i18n');

it('rebuilds the index when a translation changes', function (): void {
    $fixture = TranslatableFixture::create([
        'title' => ['el' => 'Παλιό όνομα', 'en' => 'Old name'],
    ]);

    $fixture->setTranslation('title', 'en', 'New name');
    $fixture->save();

    // A stale index is silent: the boat is still on the list, still bookable,
    // and simply cannot be found by the name it now has.
    $index = (string) $fixture->fresh()?->getAttribute(TranslationColumns::SEARCH);

    expect($index)->toContain('new name');
    expect($index)->not->toContain('old name');
})->group('fast', 'i18n');

it('finds a row by an accented term typed without accents', function (): void {
    TranslatableFixture::create([
        'title' => ['el' => 'Ύδρα', 'en' => 'Hydra'],
    ]);

    // Operators type Greek without accents about half the time, and guests
    // almost always do.
    $found = TranslatableFixture::query()->whereTranslationMatches('υδρα')->count();

    expect($found)->toBe(1);
})->group('fast', 'i18n');

it('matches καΐκι and καικι against the same records', function (): void {
    // The issue's own example, verbatim. `ΐ` is iota with both dialytika and
    // tonos — two marks on one letter — and it is in the word this product is
    // named after, so it is the case that would be noticed last and hurt most.
    TranslatableFixture::create([
        'title' => ['el' => 'Παραδοσιακό καΐκι', 'en' => 'Traditional caique'],
    ]);

    foreach (['καΐκι', 'καικι', 'ΚΑΪΚΙ', 'Καΐκι'] as $term) {
        expect(TranslatableFixture::query()->whereTranslationMatches($term)->count())
            ->toBe(1, "searching for [{$term}] found nothing");
    }
})->group('fast', 'i18n');

it('matches a final sigma against stored medial sigma and the reverse', function (): void {
    // Greek writes the same letter differently at the end of a word, so the
    // stored haystack holds `οδυσσεασ` with a **medial** sigma. Both directions
    // are asserted: `οδυσσέας` is a search ending in a final sigma against
    // that medial one, and `οδυσσεασ` is the reverse.
    TranslatableFixture::create([
        'title' => ['el' => 'Οδυσσέας', 'en' => 'Odysseas'],
    ]);

    foreach (['οδυσσεασ', 'οδυσσέας', 'Οδυσσεας'] as $term) {
        expect(TranslatableFixture::query()->whereTranslationMatches($term)->count())
            ->toBe(1, "searching for [{$term}] found nothing");
    }
})->group('fast', 'i18n');

it('finds a Greek row from an English term and the reverse', function (): void {
    TranslatableFixture::create([
        'title' => ['el' => 'Σπέτσες', 'en' => 'Spetses'],
    ]);

    expect(TranslatableFixture::query()->whereTranslationMatches('Spetses')->count())->toBe(1)
        ->and(TranslatableFixture::query()->whereTranslationMatches('ΣΠΕΤΣΕΣ')->count())->toBe(1);
})->group('fast', 'i18n');

it('shows everything for an empty search box', function (): void {
    TranslatableFixture::create(['title' => ['el' => 'Ένα', 'en' => 'One']]);
    TranslatableFixture::create(['title' => ['el' => 'Δύο', 'en' => 'Two']]);

    // Null, empty and whitespace-only all mean "the operator has not searched
    // yet". Returning nothing there is a list that appears broken on load.
    foreach ([null, '', '   '] as $term) {
        expect(TranslatableFixture::query()->whereTranslationMatches($term)->count())->toBe(2);
    }
})->group('fast', 'i18n');

it('treats a wildcard character as nothing rather than as a wildcard', function (): void {
    TranslatableFixture::create(['title' => ['el' => 'Αίγινα', 'en' => 'Aegina']]);
    TranslatableFixture::create(['title' => ['el' => 'Ύδρα', 'en' => 'Hydra']]);

    // `%` and `_` are stripped, not escaped: escaping needs a trailing ESCAPE
    // clause to mean anything on SQLite while MySQL assumes one, and ENV-12
    // forbids branching on the driver.
    //
    // Stripped means *dropped*, not *matched*. `aeg%ina` is a search for
    // "aegina" with a stray character in it and finds the island; `aeg%a` is
    // not a search for anything and finds nothing — where a real wildcard would
    // have matched. And a lone `%` is an empty search box, which shows
    // everything rather than nothing.
    expect(TranslatableFixture::query()->whereTranslationMatches('%')->count())->toBe(2)
        ->and(TranslatableFixture::query()->whereTranslationMatches('aeg%ina')->count())->toBe(1)
        ->and(TranslatableFixture::query()->whereTranslationMatches('aeg%a')->count())->toBe(0);
})->group('fast', 'i18n');

it('qualifies the search column, so it survives a join', function (): void {
    TranslatableFixture::create(['title' => ['el' => 'Πόρος', 'en' => 'Poros']]);

    // Unqualified, this is an "ambiguous column name" the day a real query
    // joins two tables that both have a `search_index` — which is every
    // catalogue table in data-model §1.6.
    $sql = TranslatableFixture::query()->whereTranslationMatches('poros')->toSql();

    expect($sql)->toContain('translatable_fixtures');
})->group('fast', 'i18n');
