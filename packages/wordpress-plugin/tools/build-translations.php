<?php

declare(strict_types=1);

/**
 * Compile the plugin's `.po` files into the `.mo` files WordPress reads.
 *
 * ## Why this exists rather than `msgfmt`
 *
 * WPP-3 requires the settings page to say what it says **in both locales**, so
 * the Greek translation is a deliverable rather than a nicety. Compiling it
 * normally means gettext's `msgfmt`, which is not on a Windows developer's
 * machine and not on a GitHub runner without an install step — and a build step
 * that only some people can run is a translation that silently goes stale.
 *
 * The `.mo` format is small and completely specified: a header, two tables of
 * offsets, and the strings. Eighty lines of PHP is cheaper than a dependency
 * and it runs everywhere PHP does, which is everywhere this project already is.
 *
 * Run: `php packages/wordpress-plugin/tools/build-translations.php`
 *
 * @package Kaiki\Booking
 */

$languages = __DIR__ . '/../kaiki-booking/languages';

/**
 * The editor script whose strings WordPress serves as JSON.
 *
 * Path **relative to the plugin directory**, because that is what WordPress
 * hashes to find the file.
 */
const KAIKI_SCRIPT = 'assets/blocks.js';

foreach (glob($languages . '/*.po') ?: [] as $po) {
    $entries = kaiki_parse_po($po);
    $mo = substr($po, 0, -3) . '.mo';

    file_put_contents($mo, kaiki_compile_mo($entries));

    printf("%s - %d strings\n", basename($mo), count($entries));

    // And the JSON half. PHP strings come from the `.mo`; **JavaScript strings
    // do not** - `wp.i18n.__` reads a JSON file whose name carries an md5 of
    // the script's path, so a plugin shipping only a `.mo` has a block editor
    // panel in English on an otherwise Greek site. Normally this is
    // `wp i18n make-json`, which means WP-CLI on every machine that builds; the
    // format is a dictionary and this is nine lines.
    $locale = kaiki_locale_of($po);

    if ($locale === null) {
        continue;
    }

    $json = sprintf('%s/kaiki-booking-%s-%s.json', $languages, $locale, md5(KAIKI_SCRIPT));

    file_put_contents($json, kaiki_compile_json($entries, $locale));

    printf("%s\n", basename($json));
}

/** The locale from a `.po` filename: `kaiki-booking-el.po` gives `el`. */
function kaiki_locale_of(string $path): ?string
{
    return preg_match('/kaiki-booking-(?P<locale>[A-Za-z_]+)\.po$/', basename($path), $matches) === 1
        ? $matches['locale']
        : null;
}

/**
 * The Jed-shaped JSON `wp.i18n` reads.
 *
 * @param array<string, string> $entries
 */
function kaiki_compile_json(array $entries, string $locale): string
{
    $strings = ['' => ['domain' => 'messages', 'lang' => $locale]];

    foreach ($entries as $original => $translation) {
        $strings[$original] = [$translation];
    }

    return (string) json_encode([
        'translation-revision-date' => gmdate('Y-m-d H:i:sO'),
        'generator' => 'kaiki/tools/build-translations.php',
        'source' => KAIKI_SCRIPT,
        'domain' => 'messages',
        'locale_data' => ['messages' => $strings],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * The translated pairs of a `.po` file.
 *
 * Deliberately small: `msgid`, `msgstr`, multi-line continuations, and nothing
 * else. No plurals and no contexts, because the plugin uses neither — and a
 * parser that pretended to handle them would be a parser that handled them
 * wrongly the first time somebody used one.
 *
 * @return array<string, string>
 */
function kaiki_parse_po(string $path): array
{
    $entries = [];
    $id = null;
    $str = null;
    $mode = null;

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'msgid ')) {
            if (is_string($id) && is_string($str) && $id !== '' && $str !== '') {
                $entries[$id] = $str;
            }

            $id = kaiki_po_string(substr($line, 6));
            $str = null;
            $mode = 'id';

            continue;
        }

        if (str_starts_with($line, 'msgstr ')) {
            $str = kaiki_po_string(substr($line, 7));
            $mode = 'str';

            continue;
        }

        if (str_starts_with($line, '"')) {
            $piece = kaiki_po_string($line);

            if ($mode === 'id') {
                $id .= $piece;
            } elseif ($mode === 'str') {
                $str .= $piece;
            }
        }
    }

    if (is_string($id) && is_string($str) && $id !== '' && $str !== '') {
        $entries[$id] = $str;
    }

    return $entries;
}

/** One quoted `.po` fragment, unescaped. */
function kaiki_po_string(string $raw): string
{
    $raw = trim($raw);

    if (strlen($raw) < 2) {
        return '';
    }

    return stripcslashes(substr($raw, 1, -1));
}

/**
 * The `.mo` binary.
 *
 * Little-endian, magic `0x950412de`, revision 0. The two tables hold the
 * length and offset of every original and every translation; WordPress reads
 * them with a binary search, which is why both must be sorted by original.
 *
 * @param array<string, string> $entries
 */
function kaiki_compile_mo(array $entries): string
{
    ksort($entries);

    $originals = array_keys($entries);
    $translations = array_values($entries);
    $count = count($entries);

    $originalTableOffset = 28;
    $translationTableOffset = $originalTableOffset + $count * 8;
    $hashOffset = $translationTableOffset + $count * 8;

    $originalsBlob = '';
    $translationsBlob = '';
    $originalTable = '';
    $translationTable = '';

    $originalStart = $hashOffset;

    foreach ($originals as $original) {
        $originalTable .= pack('VV', strlen($original), $originalStart + strlen($originalsBlob));
        $originalsBlob .= $original . "\0";
    }

    $translationStart = $originalStart + strlen($originalsBlob);

    foreach ($translations as $translation) {
        $translationTable .= pack('VV', strlen($translation), $translationStart + strlen($translationsBlob));
        $translationsBlob .= $translation . "\0";
    }

    return pack(
        'VVVVVVV',
        0x950412de,
        0,
        $count,
        $originalTableOffset,
        $translationTableOffset,
        0,
        $hashOffset,
    ) . $originalTable . $translationTable . $originalsBlob . $translationsBlob;
}
