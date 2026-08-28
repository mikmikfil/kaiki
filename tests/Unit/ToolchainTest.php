<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

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

it('is excluded from composer test because it needs mysql', function (): void {
    // `composer test` excludes the mysql group, so this must not run locally.
    // `composer test:mysql` runs it. It is a placeholder until the overselling
    // concurrency test lands in M2 (ADR-0006, spec AVL-43, ENV-11).
    expect(config('database.default'))->not->toBeEmpty();
})->group('mysql');

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
