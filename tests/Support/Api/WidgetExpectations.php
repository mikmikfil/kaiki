<?php

declare(strict_types=1);

namespace Tests\Support\Api;

use RuntimeException;

/**
 * The field names a widget TypeScript interface declares.
 *
 * ## Why read the widget's source rather than write the list down
 *
 * ADR-0011's third release gate is "an API compatibility test of that widget
 * version against `/api/v1`", and a hand-written list of fields is not that: it
 * is a third opinion, next to the widget's and the API's, that agrees with
 * neither the day someone changes one. Reading the interfaces means the gate
 * fails when the widget starts wanting a field the API does not send, which is
 * exactly the release that breaks every embed at once.
 *
 * Deliberately a scanner and not a TypeScript parser. The shapes are a handful
 * of flat `readonly name?: type;` declarations written in this repository, and
 * a parser would be a dependency ARC-19 makes a hard stop.
 */
final class WidgetExpectations
{
    /**
     * The top-level field names of one `interface` block.
     *
     * Nested object literals are skipped rather than flattened — {@see nested()}
     * reads those — because a caller asserting on `colors` wants the key, and a
     * caller asserting on the colours wants the five names inside it.
     *
     * @return list<string>
     */
    public static function fields(string $path, string $interface): array
    {
        $block = self::block($path, $interface);

        $depth = 0;
        $names = [];

        foreach (explode("\n", $block) as $line) {
            if ($depth === 0 && preg_match('/^\s*(?:readonly\s+)?(?P<name>[a-z_][a-z0-9_]*)\??:/i', $line, $matches) === 1) {
                $names[] = $matches['name'];
            }

            // Counted after the match so the line opening a nested literal still
            // contributes its own name.
            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return $names;
    }

    /**
     * The field names inside one nested object literal of an interface.
     *
     * @return list<string>
     */
    public static function nested(string $path, string $interface, string $field): array
    {
        $block = self::block($path, $interface);

        $pattern = '/^\s*(?:readonly\s+)?' . preg_quote($field, '/') . '\??:\s*\{(?P<inner>[^}]*)\}/ms';

        if (preg_match($pattern, $block, $matches) !== 1) {
            throw new RuntimeException("No nested object `{$field}` in `{$interface}` in {$path}.");
        }

        preg_match_all('/^\s*(?:readonly\s+)?(?P<name>[a-z_][a-z0-9_]*)\??:/im', $matches['inner'], $found);

        return $found['name'];
    }

    /**
     * The keys of the object literal a named function returns.
     *
     * The read half of this class asks what the widget *expects*; this asks what
     * it *sends*, which is the half that was missing when three field names in
     * `draftPayload` disagreed with the contract for two whole issues — a mock
     * transport reads no field name, so nothing noticed until a browser posted
     * to a real endpoint.
     *
     * Nested literals are skipped; {@see self::nested()} reads an interface's,
     * and {@see self::returnedNestedKeys()} reads a function's.
     *
     * @return list<string>
     */
    public static function returnedKeys(string $path, string $function): array
    {
        $body = self::returnBlock($path, $function);

        $depth = 0;
        $names = [];

        foreach (explode('
', $body) as $line) {
            if ($depth === 0 && preg_match('/^\s*(?P<name>[a-z_][a-z0-9_]*)\s*:/i', $line, $matches) === 1) {
                $names[] = $matches['name'];
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return $names;
    }

    /**
     * The keys of one nested literal inside a returned object.
     *
     * @return list<string>
     */
    public static function returnedNestedKeys(string $path, string $function, string $field): array
    {
        $body = self::returnBlock($path, $function);

        $pattern = '/^\s*' . preg_quote($field, '/') . '\s*:\s*\{(?P<inner>[^}]*)\}/ms';

        if (preg_match($pattern, $body, $matches) !== 1) {
            throw new RuntimeException("No nested literal `{$field}` returned by `{$function}` in {$path}.");
        }

        preg_match_all('/^\s*(?P<name>[a-z_][a-z0-9_]*)\s*:/im', $matches['inner'], $found);

        return $found['name'];
    }

    private static function returnBlock(string $path, string $function): string
    {
        $source = @file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("No widget source at {$path}.");
        }

        // From `return {` inside the named function to the closing brace at the
        // same indentation. Every payload builder in this package is written
        // that way, and a parser would be a dependency ARC-19 forbids.
        $pattern = '/function\s+' . preg_quote($function, '/') . '\s*\([^)]*\)[^{]*\{.*?return \{(?P<body>.*?)^  \};/ms';

        if (preg_match($pattern, $source, $matches) !== 1) {
            throw new RuntimeException("No object literal returned by `{$function}` in {$path}.");
        }

        return $matches['body'];
    }

    private static function block(string $path, string $interface): string
    {
        $source = @file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("No widget source at {$path}.");
        }

        // Up to the first closing brace at column zero, which is where every
        // interface in this package ends.
        $pattern = '/^(?:export\s+)?interface\s+' . preg_quote($interface, '/') . '\s*\{(?P<body>.*?)^\}/ms';

        if (preg_match($pattern, $source, $matches) !== 1) {
            throw new RuntimeException("No interface `{$interface}` in {$path}.");
        }

        return $matches['body'];
    }
}
