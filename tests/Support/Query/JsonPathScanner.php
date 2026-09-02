<?php

declare(strict_types=1);

namespace Tests\Support\Query;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds queries that sort or filter on a JSON path.
 *
 * Spec ENV-8 and CAT-6, and ADR-0008's acceptance note in as many words: *"A
 * PHPStan or architecture test forbids `json_extract` / `->>` inside `orderBy`
 * and `where` on translatable columns."*
 *
 * The reason is portability, not taste. MySQL 8 and SQLite both have JSON
 * support and disagree about what it means: `JSON_UNQUOTE(JSON_EXTRACT(…))`
 * under `utf8mb4_unicode_ci` folds tonos, SQLite's `json_extract` under
 * `NOCASE` folds nothing outside ASCII, and the two therefore return different
 * rows and different orders for the same Greek catalogue. Local dev runs on
 * SQLite (ADR-0015) and production on MySQL, so the developer sees the answer
 * that is not the one operators get.
 *
 * The supported path is the companion columns — `search_index` and
 * `{attribute}_sort_{locale}` — reached through `whereTranslationMatches()` and
 * `orderByTranslation()`. Both sides of every comparison then go through
 * `GreekText`, so the database only ever compares bytes this application
 * produced.
 *
 * ## A line scanner, again
 *
 * `nikic/php-parser` is not an approved dependency and ARC-21 makes adding one
 * a hard stop, so this reads lines — the same call `LiteralScanner` and
 * `WorkflowFile` made before it. Unlike the literal scanner it can afford to be
 * strict rather than forgiving: `->>`, `json_extract(` and a `->` inside a
 * column-name string have no innocent explanation in this codebase, so there is
 * no allow-list and no false positive to soften.
 */
final class JsonPathScanner
{
    /**
     * Query-builder methods whose first string argument is a column name.
     *
     * `pluck`, `value` and `select` are here alongside the obvious ones because
     * `pluck('title->el')` is the same portability problem wearing a different
     * hat — and it is the shape someone reaches for when a list "just needs the
     * Greek title".
     */
    private const COLUMN_METHODS = [
        'where', 'orWhere', 'whereNot', 'orWhereNot', 'whereIn', 'orWhereIn',
        'whereNotIn', 'whereNull', 'whereNotNull', 'whereBetween', 'whereLike',
        'orderBy', 'orderByDesc', 'latest', 'oldest', 'groupBy', 'having',
        'orHaving', 'pluck', 'value', 'select', 'addSelect', 'reorder',
    ];

    /**
     * @param  list<string>  $paths  directories to scan, relative to the project root
     * @return list<array{file: string, line: int, match: string, why: string}>
     */
    public static function scan(array $paths): array
    {
        $findings = [];

        foreach (self::files($paths) as $file) {
            $relative = self::relative($file);

            foreach (self::inspect((string) file_get_contents($file)) as $finding) {
                $findings[] = ['file' => $relative, ...$finding];
            }
        }

        return $findings;
    }

    /**
     * Just the offending fragments, for the self-test.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function matches(array $paths): array
    {
        return array_values(array_map(
            static fn (array $finding): string => $finding['match'],
            self::scan($paths),
        ));
    }

    /**
     * @return list<array{line: int, match: string, why: string}>
     */
    private static function inspect(string $contents): array
    {
        $findings = [];
        $methods = implode('|', self::COLUMN_METHODS);

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            $line = (string) $line;

            // A comment explaining why JSON paths are forbidden must not itself
            // trip the gate — otherwise the only way to document the rule is to
            // break it. Docblocks and `//` lines are skipped, which is safe
            // because a query cannot live in one.
            if (preg_match('/^\s*(\/\/|\*|\/\*)/', $line) === 1) {
                continue;
            }

            foreach (self::patterns($methods) as $why => $pattern) {
                if (preg_match($pattern, $line, $found) !== 1) {
                    continue;
                }

                $findings[] = [
                    'line' => $index + 1,
                    'match' => trim($found[1] ?? $found[0]),
                    'why' => $why,
                ];
            }
        }

        return $findings;
    }

    /**
     * @return array<string, string> why => pattern
     */
    private static function patterns(string $methods): array
    {
        return [
            // `->where('title->el', …)`, `->orderBy('title->el')`. Laravel's own
            // arrow syntax, which compiles to json_extract on both engines.
            'a JSON path as a column name' => "/->(?:{$methods})\\s*\\(\\s*['\"]([^'\"]*->[^'\"]*)['\"]/i",

            // The same thing hand-written, in `whereRaw`, `orderByRaw`,
            // `selectRaw`, `DB::raw` or a migration.
            'json_extract in raw SQL' => '/(json_extract\s*\()/i',
            'json_unquote in raw SQL' => '/(json_unquote\s*\()/i',

            // MySQL's inline JSON operator. It has no SQLite equivalent at all,
            // so this one does not merely sort differently — it fails.
            'the ->> JSON operator' => '/(->>)/',
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function files(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = base_path($path);

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = (string) $file->getRealPath();
                }
            }
        }

        sort($files);

        return $files;
    }

    private static function relative(string $file): string
    {
        return str_replace('\\', '/', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file));
    }
}
