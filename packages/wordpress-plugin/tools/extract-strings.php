<?php

declare(strict_types=1);

/**
 * The plugin's translatable strings, as a `.pot`.
 *
 * A very small `xgettext`: it finds `__()`, `esc_html__()` and `esc_attr__()`
 * with the plugin's text domain and writes the template a translator works from.
 * Enough for a plugin with one screen, and honestly less than gettext does —
 * no plurals, no contexts, because the plugin uses neither.
 *
 * Run: `php packages/wordpress-plugin/tools/extract-strings.php`
 *
 * @package Kaiki\Booking
 */

$root = __DIR__ . '/../kaiki-booking';

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

$strings = [];

foreach ($files as $file) {
    // JavaScript as well as PHP. The block editor's own labels go through
    // `wp.i18n.__` with the same text domain, and an extractor that read only
    // PHP would leave them out of the template — so they would look translated
    // (the call is there) and appear in English (the string is not).
    if (! in_array($file->getExtension(), ['php', 'js'], true) || str_contains((string) $file, 'vendor')) {
        continue;
    }

    $contents = (string) file_get_contents((string) $file);

    preg_match_all(
        "/(?:__|esc_html__|esc_attr__)\(\s*'(?P<text>(?:[^'\\\\]|\\\\.)*)'\s*,\s*'kaiki-booking'\s*\)/",
        $contents,
        $found,
    );

    foreach ($found['text'] as $text) {
        // The source is PHP single-quoted, so only `\'` and `\\` are escapes.
        $strings[str_replace(["\\'", '\\\\'], ["'", '\\'], $text)] = true;
    }
}

$keys = array_keys($strings);

sort($keys);

$out = <<<'HEAD'
# Kaiki Booking — translation template.
# Regenerate with: php packages/wordpress-plugin/tools/extract-strings.php
msgid ""
msgstr ""
"Project-Id-Version: Kaiki Booking\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"X-Domain: kaiki-booking\n"


HEAD;

foreach ($keys as $key) {
    $escaped = addcslashes($key, "\"\\\n\t");

    $out .= "msgid \"{$escaped}\"\nmsgstr \"\"\n\n";
}

file_put_contents($root . '/languages/kaiki-booking.pot', $out);

printf("kaiki-booking.pot — %d strings\n", count($keys));
