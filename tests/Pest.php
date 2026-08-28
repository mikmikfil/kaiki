<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    ->beforeEach(fn () => Cache::flush())
    ->in('Feature');

pest()->extend(TestCase::class)
    ->beforeEach(fn () => Cache::flush())
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Why the cache is flushed between tests
|--------------------------------------------------------------------------
|
| `RefreshDatabase` rolls back the database, so auto-increment ids restart at 1
| in every test. The cache is not rolled back. With the array driver that is
| harmless, because the store dies with the process — but in CI the cache is
| **real Redis**, shared across the whole run, and an entry keyed on a model id
| written by one test is read by the next test's *different* model that happens
| to have the same id.
|
| That is not hypothetical: it failed the MySQL + Redis job on the API-key
| throttle tests while SQLite stayed green. Anything that caches per-id needs
| this, and the failure mode is order-dependent flakiness, which is the most
| expensive kind to diagnose later.
|
*/

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
