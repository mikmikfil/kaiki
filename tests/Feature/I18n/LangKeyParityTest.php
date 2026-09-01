<?php

declare(strict_types=1);

use App\Support\Locale\LocaleResolver;
use Tests\Support\I18n\AllowList;
use Tests\Support\I18n\LangFiles;

/*
 * Spec I18N-3: the EL and EN lang files MUST have identical key sets, and CI
 * fails on a missing or orphaned key in either direction.
 *
 * The failure this prevents is silent by construction. Laravel returns the key
 * itself when a translation is missing, so a forgotten Greek string renders as
 * `panel.groups.operations` on the page — perfectly functional, obviously
 * broken, and nobody files a bug about it because the operator assumes that is
 * what the software looks like.
 *
 * Both directions matter. A missing Greek key shows a dotted path to the
 * primary audience; an orphaned Greek key is dead weight that survives a
 * rename and quietly stops matching anything.
 */

it('ships a lang directory for every locale the application claims to support', function (): void {
    foreach (LocaleResolver::installed() as $locale) {
        expect(is_dir(lang_path($locale)))
            ->toBeTrue("config('app.available_locales') lists `{$locale}` but lang/{$locale} does not exist");
    }
})->group('fast', 'i18n');

it('has the same lang files in every locale', function (): void {
    // A whole file present in one locale and not the other is the coarsest
    // version of this failure and the easiest to miss in review.
    $reference = LangFiles::filenames(LocaleResolver::FALLBACK);

    foreach (LocaleResolver::installed() as $locale) {
        expect(LangFiles::filenames($locale))->toBe(
            $reference,
            "lang/{$locale} does not contain the same files as lang/" . LocaleResolver::FALLBACK,
        );
    }
})->group('fast', 'i18n');

it('covers at least the areas the panel and the API depend on', function (): void {
    // Named explicitly so that deleting one is a decision rather than an
    // accident. `enums` is the home for every backed enum's label (CNV-11).
    expect(LangFiles::filenames('en'))
        ->toContain('validation', 'auth', 'panel', 'errors', 'api', 'enums');
})->group('fast', 'i18n');

it('has identical key sets in Greek and English, in both directions', function (): void {
    $en = LangFiles::allKeys('en');
    $el = LangFiles::allKeys('el');

    // Reported as two named lists rather than a set comparison, so a red build
    // says which key is wrong and where instead of dumping both trees.
    $missingInGreek = array_values(array_diff($en, $el));
    $orphanedInGreek = array_values(array_diff($el, $en));

    expect($missingInGreek)->toBe([], 'missing from lang/el: ' . implode(', ', $missingInGreek));
    expect($orphanedInGreek)->toBe([], 'not present in lang/en: ' . implode(', ', $orphanedInGreek));
})->group('fast', 'i18n');

it('leaves no key untranslated in Greek', function (): void {
    // Parity alone passes for a Greek file that is a copy of the English one.
    // Greek is the primary operator language, so an English sentence sitting in
    // lang/el is the defect this catches — a file where half the work was done.
    $untranslated = [];
    $allowed = AllowList::identicalTranslations();

    foreach (LangFiles::filenames('en') as $file) {
        $en = LangFiles::flattenWithValues(LangFiles::load('en', $file));
        $el = LangFiles::flattenWithValues(LangFiles::load('el', $file));

        foreach ($en as $key => $english) {
            $greek = $el[$key] ?? null;

            if (! is_string($english) || ! is_string($greek)) {
                continue;
            }

            // Latin-script identity is the signal. A few strings are legitimately
            // identical across locales — anything that is a proper noun, a code
            // or a symbol — so the test only fires when the string contains
            // letters that a Greek translation would have replaced.
            if ($greek !== $english || preg_match('/\p{Ll}\p{Ll}\p{Ll}/u', $english) !== 1) {
                continue;
            }

            // Proper nouns, product names, codes and example URLs are the same
            // string in both locales. Each exemption is named with a reason in
            // `tests/Support/I18n/allow-list.php`.
            if (! array_key_exists("{$file}.{$key}", $allowed)) {
                $untranslated[] = "{$file}.{$key}";
            }
        }
    }

    expect($untranslated)->toBe([], 'still English in lang/el: ' . implode(', ', $untranslated));
})->group('fast', 'i18n');

it('keeps every placeholder that the English string uses', function (): void {
    // A dropped `:attribute` renders the literal word, so a Greek operator gets
    // "Το πεδίο :attribute είναι υποχρεωτικό" — or worse, a message that names
    // no field at all and leaves them hunting the form for what went wrong.
    $mismatches = [];

    foreach (LangFiles::filenames('en') as $file) {
        $en = LangFiles::flattenWithValues(LangFiles::load('en', $file));
        $el = LangFiles::flattenWithValues(LangFiles::load('el', $file));

        foreach ($en as $key => $english) {
            $greek = $el[$key] ?? null;

            if (! is_string($english) || ! is_string($greek)) {
                continue;
            }

            preg_match_all('/:([a-z_]+)/', $english, $matches);

            foreach (array_unique($matches[1]) as $placeholder) {
                if (! str_contains($greek, ":{$placeholder}")) {
                    $mismatches[] = "{$file}.{$key} is missing :{$placeholder}";
                }
            }
        }
    }

    expect($mismatches)->toBe([], implode('; ', $mismatches));
})->group('fast', 'i18n');

it('has no empty string anywhere', function (): void {
    // An empty translation renders as nothing at all, which is the one failure
    // mode that looks like a layout bug rather than a missing string.
    $empty = [];

    foreach (LocaleResolver::installed() as $locale) {
        foreach (LangFiles::filenames($locale) as $file) {
            $lines = LangFiles::flattenWithValues(LangFiles::load($locale, $file));

            foreach ($lines as $key => $value) {
                if (is_string($value) && trim($value) === '') {
                    $empty[] = "{$locale}/{$file}.{$key}";
                }
            }
        }
    }

    expect($empty)->toBe([], 'empty translations: ' . implode(', ', $empty));
})->group('fast', 'i18n');
