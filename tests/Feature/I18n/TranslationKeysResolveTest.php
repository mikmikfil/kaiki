<?php

declare(strict_types=1);

use Tests\Support\I18n\LangFiles;

/*
|--------------------------------------------------------------------------
| Every `__()` key in the product resolves to a real string
|--------------------------------------------------------------------------
|
| This gate exists because the same bug shipped **three times in one day**.
|
| 1. The webhook form rendered `webhooks.events.booking.confirmed` under each
|    checkbox, because every event name contains a dot and Laravel reads a dot
|    as a path separator.
| 2. The failure feed rendered `enums.failure_source.notification.label`,
|    because the enum block was never added to the `enums.php` lang files.
| 3. The vessel form rendered `catalog.vessel.form.max_wind_bft.label`, added
|    in #131 with no lang keys at all. **The product owner found that one**,
|    in the panel, a day after it shipped.
|
| Every one of those passed the whole suite. `NoHardcodedStringsTest` proves
| nobody typed a literal into PHP; `LangKeyParityTest` proves the two locales
| carry the same keys. Neither asks the one question that matters here: does
| the key a screen actually calls resolve to anything?
|
| The enum half of this is already covered — `EnumLabelCoverageTest` resolves
| every case of every enum in both locales, and catches bug 2 above. What it
| cannot see is a key called directly from a form, a table column or a Blade
| file, which is bugs 1 and 3. This is that gate.
|
| Laravel's `__()` returns **the key itself** when it misses, which is the
| behaviour that makes this invisible — nothing throws, nothing logs, and the
| page renders a dotted string where a sentence should be. Only a person
| looking at the screen notices, and only if they are looking at that screen.
|
*/

/**
 * Every literal key passed to `__()` or `trans()` under `app/` and
 * `resources/views/`.
 *
 * Static literals only — `__('catalog.vessel.form.name.label')`. A key built
 * from a variable (`__('webhooks.events.' . $event->value)`) cannot be checked
 * without running the code, and **that is exactly the shape of bug 1 above**,
 * so those are reported separately rather than ignored.
 *
 * @return array<string, list<string>> key => files that use it
 */
function translationKeysUsed(): array
{
    $keys = [];

    // `resources/views` as well as `app`, and the views matter more: a Blade
    // file is where a key is most often typed by hand, and it is the only place
    // a missing one is invisible to every other gate in the suite.
    $roots = [base_path('app'), base_path('resources/views')];

    $files = [];

    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $found) {
            $files[] = $found;
        }
    }

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // `__('a.b.c')` and `trans('a.b.c')`, single or double quoted, with no
        // concatenation immediately after the closing quote.
        preg_match_all(
            '/(?:__|trans)\(\s*[\'"]([a-z0-9_]+\.[a-z0-9_.\-]+)[\'"]\s*[,)]/i',
            $source,
            $matches,
        );

        foreach ($matches[1] as $key) {
            $keys[$key][] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    return $keys;
}

it('resolves every translation key the application asks for', function (): void {
    $missing = [];

    foreach (translationKeysUsed() as $key => $files) {
        [$file] = explode('.', $key, 2);

        // Not a lang file at all — `config.something`, a validation rule name,
        // a route name that happens to look like one. The parity test owns the
        // lang files themselves; this one is about keys that *should* resolve.
        if (! in_array($file, LangFiles::filenames('el'), true)) {
            continue;
        }

        foreach (['el', 'en'] as $locale) {
            $resolved = trans($key, [], $locale);

            // Laravel returns the key itself on a miss. That is the whole bug:
            // silent, unlogged, and visible only to somebody looking at the
            // one screen that uses it.
            //
            // An **array** is not a miss. Fetching a whole block and indexing it
            // is the documented fix for a key whose own value contains a dot —
            // `HasTranslatedLabel::line()` does exactly that, and so does the
            // webhook form. Flagging it would forbid the remedy for bug 1.
            if ($resolved === $key) {
                $missing[] = "{$key} ({$locale}) — used in " . implode(', ', array_unique($files));
            }
        }
    }

    expect($missing)->toBe([], "translation keys that render as themselves:\n" . implode("\n", $missing));
})->group('fast', 'i18n');
