<?php

declare(strict_types=1);

use Tests\Support\Query\JsonPathScanner;

/*
 * Spec ENV-8 / CAT-6, and ADR-0008's acceptance note: no query may sort or
 * filter on a JSON path.
 *
 * The rule has held so far because there is nothing to query yet — the first
 * translatable table is #16. That is exactly when to wire the gate: today it
 * costs nothing to make green, and from here every catalogue screen either uses
 * the companion columns or turns the build red.
 *
 * Left ungated, the failure is invisible in development. `json_extract` works
 * on SQLite; the local product list sorts, the local search finds things, the
 * tests pass. It is production, on MySQL, where Greek sorts into a different
 * order and an accented search stops matching — and by then the query is
 * copied across a dozen resources.
 */

/**
 * Where a JSON-path query would be a defect.
 *
 * @return list<string>
 */
function jsonPathScannedPaths(): array
{
    return [
        'app',
        // Migrations too: a functional index on a JSON path is the same
        // portability problem, and data-model §0 forbids one outright because
        // SQLite cannot build it at all.
        'database',
    ];
}

it('has no query that sorts or filters on a JSON path', function (): void {
    $findings = JsonPathScanner::scan(jsonPathScannedPaths());

    $report = array_map(
        static fn (array $f): string => "{$f['file']}:{$f['line']} — \"{$f['match']}\" ({$f['why']})",
        $findings,
    );

    expect($report)->toBe([], "JSON-path queries:\n" . implode("\n", $report));
})->group('fast', 'i18n');

it('can actually detect every forbidden shape', function (): void {
    $matches = JsonPathScanner::matches(['tests/Support/Query/Fixtures']);

    expect($matches)
        ->toContain('title->el')                                  // orderBy with Laravel's arrow
        ->toContain('title->en')                                  // where with Laravel's arrow
        ->toContain('summary->el')                                // pluck, which is the one people forget
        ->toContain('json_extract(')                              // hand-written, lowercase
        ->toContain('JSON_UNQUOTE(')                              // hand-written, upper
        ->toContain('->>');                                       // MySQL's inline operator
})->group('fast', 'i18n');

it('does not flag the companion columns it exists to push people towards', function (): void {
    // The other half of the claim. If this test can be made to pass by
    // loosening the scanner, the scanner is worthless — and these are the exact
    // shapes `whereTranslationMatches()` and `orderByTranslation()` generate.
    $matches = JsonPathScanner::matches(['tests/Support/Query/Fixtures']);

    expect($matches)->not->toContain('search_index');
    expect($matches)->not->toContain('title_sort_el');
    expect($matches)->not->toContain('status');
    expect($matches)->not->toContain('created_at');
})->group('fast', 'i18n');

it('does not flag a comment that explains the rule', function (): void {
    // Otherwise the only way to document ENV-8 in the codebase would be to
    // break it — and every docblock in `HasTranslatableSearch` names the very
    // constructs it forbids.
    $findings = JsonPathScanner::scan(['app/Models/Concerns']);

    expect($findings)->toBe([]);
})->group('fast', 'i18n');
