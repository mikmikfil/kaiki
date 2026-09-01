<?php

declare(strict_types=1);

namespace Tests\Support\I18n;

/**
 * Reads the shapes out of `lang/` that the I18N-3 parity check asserts on.
 *
 * The flattening used to be a global `flattenKeys()` function declared inside
 * `tests/Feature/Panel/ApiKeyLocaleTest.php` (#10). That worked while exactly
 * one test file needed it and would have fatal-errored the moment a second one
 * declared the same name — which is what a parity test across every lang file
 * is. It lives here instead, and both call sites use it.
 */
final class LangFiles
{
    /**
     * Every dotted key in a lang array, flattened and sorted.
     *
     * Compared as a set rather than key by key at the top level, because a key
     * added three levels down and forgotten in the other locale renders as the
     * raw dotted path on screen and nothing else goes wrong.
     *
     * @param  array<array-key, mixed>  $lines
     * @return list<string>
     */
    public static function flatten(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                // An empty array is a leaf, not a branch. `validation.custom`
                // and `validation.attributes` are both legitimately empty; if
                // they were skipped, one locale could grow a key under them and
                // the other could stay empty without the parity check noticing.
                $keys = $value === []
                    ? [...$keys, $path]
                    : [...$keys, ...self::flatten($value, $path)];

                continue;
            }

            $keys[] = $path;
        }

        sort($keys);

        return $keys;
    }

    /**
     * Dotted key => value, built during the walk.
     *
     * **Not `data_get($lines, $key)`.** Several keys legitimately contain a dot
     * — `enums.api_scope.products.read` — and `data_get` reads a dot as a path
     * separator, so it returns null for exactly those keys. The value checks
     * below used it and therefore skipped every API scope label in silence; two
     * Greek strings were reworded under cover of a "move" in #12 and nothing
     * could have noticed. Carrying the value out of the recursion, where the
     * real key is still in hand, removes the whole class of problem.
     *
     * @param  array<array-key, mixed>  $lines
     * @return array<string, mixed>
     */
    public static function flattenWithValues(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== []) {
                $flat = [...$flat, ...self::flattenWithValues($value, $path)];

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * The lang file basenames present for a locale, without the `.php`.
     *
     * @return list<string>
     */
    public static function filenames(string $locale): array
    {
        $files = glob(lang_path("{$locale}/*.php")) ?: [];

        $names = array_map(
            static fn (string $path): string => basename($path, '.php'),
            $files,
        );

        sort($names);

        return $names;
    }

    /**
     * One locale's lang file, as an array.
     *
     * @return array<array-key, mixed>
     */
    public static function load(string $locale, string $file): array
    {
        /** @var array<array-key, mixed> $lines */
        $lines = require lang_path("{$locale}/{$file}.php");

        return $lines;
    }

    /**
     * Every dotted key for a locale, namespaced by file.
     *
     * @return list<string>
     */
    public static function allKeys(string $locale): array
    {
        $keys = [];

        foreach (self::filenames($locale) as $file) {
            foreach (self::flatten(self::load($locale, $file)) as $key) {
                $keys[] = "{$file}.{$key}";
            }
        }

        sort($keys);

        return $keys;
    }
}
