<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the application and hit real routes with tenant context.
| Prefer them over unit tests of getters — see .claude/agents/qa-tester.md.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Groups
|--------------------------------------------------------------------------
|
| `composer test:fast` excludes these three. Tag a test with the group it
| genuinely needs rather than skipping it silently:
|
|   mysql     — needs MySQL 8. `SELECT ... FOR UPDATE` is a no-op on SQLite,
|               so the overselling concurrency test lives here and runs in CI
|               only (ADR-0006, spec AVL-43).
|   chromium  — needs a Chromium binary for Browsershot PDF rendering.
|               Skipped locally unless KAIKI_CHROME_PATH is set (spec ENV-20).
|   slow      — anything over a couple of seconds: benchmarks, large seeds.
|
| Usage:  it('does not oversell', ...)->group('mysql');
|
*/
