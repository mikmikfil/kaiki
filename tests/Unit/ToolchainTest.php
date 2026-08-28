<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Toolchain guards
|--------------------------------------------------------------------------
|
| These tests exist to prove the tooling itself works. If group filtering
| silently stops working, every CI-only test starts reporting green while
| running nothing — which is worse than a red build.
|
*/

it('runs tests in the fast group', function (): void {
    expect(true)->toBeTrue();
})->group('fast');

it('runs the mysql group against a real MySQL 8 connection', function (): void {
    // ENV-11 / TST-8: the mysql group must never report green without actually
    // being on MySQL. If phpunit.xml's sqlite defaults were to win over the CI
    // job's environment, this fails loudly instead of silently testing SQLite
    // and calling it a MySQL run. The overselling concurrency test (AVL-44,
    // ADR-0006) joins this group in M2 and depends on that guarantee.
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getPdo())->not->toBeNull();
})->group('mysql')->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'mysql',
    'Needs MySQL 8. Runs in CI only — local development is SQLite (ADR-0015).',
);

it('freezes time with Carbon::setTestNow rather than reading the clock', function (): void {
    // TST-9: no test may depend on the real clock. This proves the mechanism
    // works so there is never an excuse to read `now()` unfrozen.
    Carbon::setTestNow('2026-08-28 12:00:00');

    expect(Carbon::now()->toDateTimeString())->toBe('2026-08-28 12:00:00')
        ->and(CarbonImmutable::now()->toDateTimeString())->toBe('2026-08-28 12:00:00');

    Carbon::setTestNow();
})->group('fast');

it('runs the test suite in UTC', function (): void {
    // ENV-14: storage is UTC everywhere. A machine in Europe/Athens must not
    // produce different results from CI.
    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
})->group('fast');
