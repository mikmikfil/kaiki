<?php

declare(strict_types=1);

use App\Support\Text\GreekText;

/*
 * Spec I18N-7, per ADR-0008: Greek sorting and searching is accent-insensitive
 * and handles final sigma, through one shared helper so that MySQL and SQLite
 * behave identically.
 *
 * #15 consumes this from the translatable observer and the query builder. It
 * lands here because both of those need the same function, and two copies of a
 * folding rule is how a search silently stops matching half its rows.
 */

it('strips tonos from every vowel that carries one', function (): void {
    expect(GreekText::fold('άέήίόύώ'))->toBe('αεηιουω');
})->group('fast', 'i18n');

it('strips dialytika, including the combined forms', function (): void {
    // ΐ and ΰ carry both a dialytika and a tonos in one character, so a map
    // keyed only on the plain diaeresis would leave them behind.
    expect(GreekText::fold('προϊόν'))->toBe('προιον')
        ->and(GreekText::fold('ΐΰ'))->toBe('ιυ');
})->group('fast', 'i18n');

it('normalises final sigma', function (): void {
    // The same letter, written differently because of where it sits in the
    // word. Without this, a guest searching "Οδυσσευς" never finds "Οδυσσεύς".
    expect(GreekText::fold('Οδυσσεύς'))->toBe('οδυσσευσ')
        ->and(GreekText::fold('οδυσσευσ'))->toBe('οδυσσευσ');
})->group('fast', 'i18n');

it('folds uppercase, including uppercase with tonos', function (): void {
    expect(GreekText::fold('ΆΝΝΑ'))->toBe('αννα')
        ->and(GreekText::fold('ΟΔΥΣΣΕΎΣ'))->toBe('οδυσσευσ');
})->group('fast', 'i18n');

it('makes the accented and unaccented spellings of a name identical', function (): void {
    // The actual product requirement: operators type vessel and port names
    // without accents about half the time.
    expect(GreekText::fold('Πλατεία Συντάγματος'))
        ->toBe(GreekText::fold('ΠΛΑΤΕΙΑ ΣΥΝΤΑΓΜΑΤΟΣ'));
})->group('fast', 'i18n');

it('is idempotent', function (): void {
    // The observer rebuilds `search_index` on every save without knowing
    // whether what it was handed has already been folded.
    $once = GreekText::fold('Άγιος Νικόλαος');

    expect(GreekText::fold($once))->toBe($once);
})->group('fast', 'i18n');

it('leaves Latin text alone apart from case', function (): void {
    // Half this catalogue is in English. Folding must not mangle it.
    expect(GreekText::fold('Blue Lagoon Cruise'))->toBe('blue lagoon cruise');
})->group('fast', 'i18n');

it('builds a search index across locales', function (): void {
    $index = GreekText::searchIndex(['Άγιος Νικόλαος', 'Agios Nikolaos', null, '']);

    expect($index)->toBe('αγιοσ νικολαοσ agios nikolaos');
})->group('fast', 'i18n');

it('collapses whitespace in the search index', function (): void {
    // A name pasted from a spreadsheet keeps its double spaces and would
    // otherwise never match the same name typed by hand.
    expect(GreekText::searchIndex(["Άγιος   Νικόλαος\n"]))->toBe('αγιοσ νικολαοσ');
})->group('fast', 'i18n');

it('matches accented text against an unaccented search term', function (): void {
    expect(GreekText::contains('Ο Οδυσσέας φεύγει', 'οδυσσεασ'))->toBeTrue()
        ->and(GreekText::contains('Ο Οδυσσέας φεύγει', 'ΟΔΥΣΣΕΑΣ'))->toBeTrue()
        ->and(GreekText::contains('Ο Οδυσσέας φεύγει', 'Ποσειδώνας'))->toBeFalse();
})->group('fast', 'i18n');

it('treats an empty search term as matching everything', function (): void {
    // An empty search box shows the whole list rather than nothing.
    expect(GreekText::contains('οτιδήποτε', ''))->toBeTrue();
})->group('fast', 'i18n');
