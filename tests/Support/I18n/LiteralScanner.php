<?php

declare(strict_types=1);

namespace Tests\Support\I18n;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds user-facing string literals that should have come from a lang file.
 *
 * Spec I18N-2: CI fails on a literal inside a Blade `{{ }}`, a Filament label,
 * or a widget component render, excluding an allow-list.
 *
 * Deliberately a line scanner rather than a PHP parser: `nikic/php-parser` is
 * not an approved dependency and ARC-21 makes adding one a hard stop. The
 * shapes being read are the ones this repository writes, and the scanner errs
 * towards **false negatives** — it would rather miss an exotic literal than
 * fail a build over a CSS class. A lint that cries wolf gets an allow-list
 * entry instead of a fix, which is how it stops meaning anything.
 */
final class LiteralScanner
{
    /**
     * Filament and Notification methods whose first argument is shown to a
     * person. `->label('Vessels')` is the canonical M1 mistake.
     */
    private const LABEL_METHODS = [
        'label', 'heading', 'description', 'placeholder', 'helperText', 'hint',
        'title', 'body', 'tooltip', 'emptyStateHeading', 'emptyStateDescription',
        'modalHeading', 'modalDescription', 'modalSubmitActionLabel',
        'modalCancelActionLabel', 'navigationLabel', 'breadcrumb',
        'successNotificationTitle', 'failureNotificationTitle',
    ];

    /**
     * Resource properties Filament renders directly.
     */
    private const LABEL_PROPERTIES = [
        'navigationLabel', 'modelLabel', 'pluralModelLabel', 'navigationGroup',
        'title', 'heading', 'subheading', 'breadcrumb',
    ];

    /**
     * @param  list<string>  $paths  directories to scan, relative to the project root
     * @return list<array{file: string, line: int, literal: string, why: string}>
     */
    public static function scan(array $paths): array
    {
        $findings = [];
        $allowed = AllowList::literals();

        foreach (self::files($paths) as $file) {
            $relative = self::relative($file);
            $contents = (string) file_get_contents($file);

            foreach (self::inspect($contents, str_ends_with($file, '.blade.php')) as $finding) {
                $number = self::lineAt($contents, $finding['offset']);

                if (array_key_exists("{$relative}:{$number}", $allowed)
                    || array_key_exists($relative, $allowed)) {
                    continue;
                }

                $findings[] = [
                    'file' => $relative,
                    'line' => $number,
                    'literal' => $finding['literal'],
                    'why' => $finding['why'],
                ];
            }
        }

        return $findings;
    }

    /**
     * Just the offending strings, for tests that assert on what was caught.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function literals(array $paths): array
    {
        return array_map(
            static fn (array $finding): string => $finding['literal'],
            self::scan($paths),
        );
    }

    /**
     * Scan a whole file at once, reporting byte offsets.
     *
     * **Whole file, not line by line.** Pint wraps a fluent chain the moment it
     * passes the line limit, so `->helperText(
    'Long sentence'
)` is what
     * a real M1 resource looks like — and a per-line scanner cannot see it.
     * Nothing in `app/` hit that shape yet; twelve resources will.
     *
     * Comments are stripped first rather than skipped per line, so a literal
     * quoted inside a docblock (this class is full of them) is not a finding.
     *
     * Known gaps, kept deliberately rather than forgotten — each would cost more
     * in false positives than it buys, and the scanner's whole value is that a
     * finding means something:
     *   - array literals, `->options(['active' => 'Active'])`
     *   - bare Blade text nodes, `<h2>Vessels</h2>`
     *   - closures returning a literal, `fn () => 'Active'`
     *
     * @return list<array{literal: string, why: string, offset: int}>
     */
    private static function inspect(string $contents, bool $isBlade): array
    {
        $contents = self::withoutComments($contents, $isBlade);

        $findings = [];

        if ($isBlade) {
            // `{{ 'Save' }}` and `{!! 'Save' !!}`. A `{{ __('…') }}` contains a
            // literal too, so the echo has to be free of any translation call
            // before its literal counts.
            preg_match_all('/\{\{(.+?)\}\}|\{!!(.+?)!!\}/s', $contents, $echoes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($echoes as $echo) {
                $expression = trim(($echo[1][0] ?? '') . ($echo[2][0] ?? ''));

                if (self::isTranslated($expression)) {
                    continue;
                }

                if (preg_match("/^'([^']*)'$|^\"([^\"]*)\"$/", $expression, $literal) === 1) {
                    $value = $literal[1] !== '' ? $literal[1] : ($literal[2] ?? '');

                    if (self::looksUserFacing($value)) {
                        $findings[] = [
                            'literal' => $value,
                            'why' => 'literal string echoed from Blade',
                            'offset' => (int) $echo[0][1],
                        ];
                    }
                }
            }
        }

        // `->label('Vessels')`, across newlines — but not `->label(__('…'))`
        // and not `->label(fn () => __('…'))`.
        $methods = implode('|', self::LABEL_METHODS);

        preg_match_all(
            "/->({$methods})\(\s*(['\"])(.*?)\\2\s*[,)]/s",
            $contents,
            $calls,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($calls as $call) {
            if (self::looksUserFacing($call[3][0])) {
                $findings[] = [
                    'literal' => $call[3][0],
                    'why' => "literal passed to ->{$call[1][0]}()",
                    'offset' => (int) $call[0][1],
                ];
            }
        }

        // `protected static ?string $navigationLabel = 'Vessels';`
        $properties = implode('|', self::LABEL_PROPERTIES);

        preg_match_all(
            "/\\$({$properties})\s*=\s*(['\"])(.*?)\\2\s*;/s",
            $contents,
            $assignments,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($assignments as $assignment) {
            if (self::looksUserFacing($assignment[3][0])) {
                $findings[] = [
                    'literal' => $assignment[3][0],
                    'why' => "literal assigned to \${$assignment[1][0]}",
                    'offset' => (int) $assignment[0][1],
                ];
            }
        }

        return $findings;
    }

    /**
     * Blank out comments, preserving byte offsets so line numbers stay true.
     *
     * Replacing with spaces rather than removing means a finding after a long
     * docblock still reports the line it is actually on.
     */
    private static function withoutComments(string $contents, bool $isBlade): string
    {
        $blank = static fn (array $m): string => (string) preg_replace('/[^
]/', ' ', $m[0]);

        $patterns = $isBlade
            ? ['/\{\{--.*?--\}\}/s']
            : ['/\/\*.*?\*\//s', '/(^|\s)\/\/[^
]*/m'];

        foreach ($patterns as $pattern) {
            $contents = (string) preg_replace_callback($pattern, $blank, $contents);
        }

        return $contents;
    }

    /** 1-indexed line number for a byte offset. */
    private static function lineAt(string $contents, int $offset): int
    {
        return substr_count($contents, '
', 0, min($offset, strlen($contents))) + 1;
    }

    /** Does this expression already route through the translator? */
    private static function isTranslated(string $expression): bool
    {
        return preg_match('/\b(__|trans|trans_choice)\s*\(|@lang\b/', $expression) === 1;
    }

    /**
     * Is this literal plausibly a sentence a person reads?
     *
     * Two or more letters and at least one space, or a capitalised word — which
     * excludes `heroicon-o-key`, `primary`, `md`, `sm`, a CSS class list, a
     * route name and a column name, none of which are user-facing and all of
     * which appear as literals constantly.
     */
    private static function looksUserFacing(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) < 2) {
            return false;
        }

        // Identifiers: snake_case, kebab-case, dotted keys, class names.
        if (preg_match('/^[a-z0-9]+([._\-\/:][a-z0-9]+)*$/', $value) === 1) {
            return false;
        }

        // Anything with a placeholder is a translation string being built.
        if (str_contains($value, '{') || str_starts_with($value, ':')) {
            return false;
        }

        // A capital letter followed by lowercase, i.e. a word rather than an
        // acronym or a constant. "Allowed websites" hits; "EL" and "UTC" miss.
        return preg_match('/\p{Lu}\p{Ll}|\p{Ll}\p{Ll}\s\p{L}/u', $value) === 1;
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

            /** @var iterable<SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                $name = $file->getFilename();

                if (str_ends_with($name, '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private static function relative(string $absolute): string
    {
        return str_replace('\\', '/', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $absolute));
    }
}
