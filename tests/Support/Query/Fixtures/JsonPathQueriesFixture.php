<?php

declare(strict_types=1);

namespace Tests\Support\Query\Fixtures;

use Illuminate\Support\Facades\DB;
use Tests\Support\Query\JsonPathScanner;

/**
 * Permanently wrong, on purpose.
 *
 * "A lint that has never failed is not a lint." Rather than asking a human to
 * sabotage the tree and remember to revert, {@see JsonPathScanner}
 * is pointed at this file, which contains every shape ENV-8 forbids and is
 * never loaded by anything.
 *
 * It is not in `app/`, so the gate over the real source stays green while this
 * proves the gate can go red.
 */
final class JsonPathQueriesFixture
{
    public function everyForbiddenShape(): void
    {
        // Laravel's own JSON arrow. Compiles to json_extract on both engines
        // and sorts Greek differently on each.
        DB::table('products')->orderBy('title->el')->get();
        DB::table('products')->where('title->en', 'like', '%cruise%')->get();
        DB::table('products')->pluck('summary->el');

        // The same thing hand-written.
        DB::table('products')->orderByRaw('json_extract(title, "$.el")')->get();
        DB::table('products')->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(title, "$.en")) = ?', ['Aegina'])->get();

        // MySQL's inline operator, which SQLite does not have at all.
        DB::table('products')->whereRaw('title->>"$.el" = ?', ['Αίγινα'])->get();
    }

    /**
     * The other half of the claim.
     *
     * These are the shapes the application actually writes, and none of them
     * may be flagged — a gate that fires on `orderByTranslation('title')` is a
     * gate somebody switches off within a week.
     */
    public function theSupportedPath(): void
    {
        DB::table('products')->where('search_index', 'like', '%αιγινα%')->get();
        DB::table('products')->orderBy('title_sort_el')->get();
        DB::table('products')->where('status', 'published')->orderByDesc('created_at')->get();
    }
}
