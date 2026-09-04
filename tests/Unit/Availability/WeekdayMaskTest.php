<?php

declare(strict_types=1);

use App\Domain\Availability\Support\WeekdayMask;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The weekday bitmask — data-model §2.3
|--------------------------------------------------------------------------
|
| **Monday is bit 0, Sunday is bit 6.** PHP disagrees with itself about this:
| `date('w')` counts Sunday as 0 and `date('N')` counts Monday as 1. A
| conversion written at a call site is one that is right in the generator and
| off by one in the panel preview, and the symptom is a trip that runs on the
| wrong day — which an operator discovers from a guest standing on the quay.
|
| So all seven single-day masks are asserted individually. A table of one is a
| table that passes with an off-by-one somewhere in the middle of the week.
|
*/

it('gives each ISO weekday its own bit, Monday first', function (int $isoWeekday, int $bit): void {
    expect(WeekdayMask::bit($isoWeekday))->toBe($bit)
        ->and(WeekdayMask::includes($bit, $isoWeekday))->toBeTrue();
})->with([
    'Monday' => [1, 1],
    'Tuesday' => [2, 2],
    'Wednesday' => [3, 4],
    'Thursday' => [4, 8],
    'Friday' => [5, 16],
    'Saturday' => [6, 32],
    'Sunday' => [7, 64],
])->group('fast');

it('matches a real date against the right bit', function (string $date, int $isoWeekday): void {
    // The assertion that catches a Sunday-first convention leaking in: these
    // are real dates, and Carbon's `isoWeekday()` is the authority.
    $mask = WeekdayMask::bit($isoWeekday);

    expect(WeekdayMask::covers($mask, Carbon::parse($date)))->toBeTrue()
        ->and(WeekdayMask::covers($mask, Carbon::parse($date)->addDay()))->toBeFalse();
})->with([
    ['2026-06-01', 1],
    ['2026-06-02', 2],
    ['2026-06-03', 3],
    ['2026-06-04', 4],
    ['2026-06-05', 5],
    ['2026-06-06', 6],
    ['2026-06-07', 7],
])->group('fast');

it('treats 127 as daily', function (): void {
    expect(WeekdayMask::DAILY)->toBe(127)
        ->and(WeekdayMask::count(WeekdayMask::DAILY))->toBe(7)
        ->and(WeekdayMask::toDays(WeekdayMask::DAILY))->toBe([1, 2, 3, 4, 5, 6, 7]);
})->group('fast');

it('round-trips a set of days', function (): void {
    // Tuesday and Thursday: how an operator describes a schedule, and what the
    // panel has to be able to write back after reading 10.
    $mask = WeekdayMask::fromDays([2, 4]);

    expect($mask)->toBe(10)
        ->and(WeekdayMask::toDays($mask))->toBe([2, 4]);
})->group('fast');

it('keeps days in Monday-first order however they arrive', function (): void {
    expect(WeekdayMask::toDays(WeekdayMask::fromDays([7, 3, 1])))->toBe([1, 3, 7]);
})->group('fast');

it('counts an empty mask as never', function (): void {
    expect(WeekdayMask::count(0))->toBe(0)
        ->and(WeekdayMask::toDays(0))->toBe([])
        ->and(WeekdayMask::covers(0, Carbon::parse('2026-06-01')))->toBeFalse();
})->group('fast');

it('lists the next dates a mask covers', function (): void {
    $dates = WeekdayMask::nextDates(WeekdayMask::fromDays([2, 4]), Carbon::parse('2026-06-01'), 4);

    expect(array_map(static fn (Carbon $d): string => $d->toDateString(), $dates))
        ->toBe(['2026-06-02', '2026-06-04', '2026-06-09', '2026-06-11']);
})->group('fast');

it('stops at the end of the validity window', function (): void {
    $dates = WeekdayMask::nextDates(
        WeekdayMask::DAILY,
        Carbon::parse('2026-06-01'),
        10,
        Carbon::parse('2026-06-03'),
    );

    expect($dates)->toHaveCount(3);
})->group('fast');

it('returns nothing rather than spinning on an empty mask', function (): void {
    // The Action refuses an empty mask, and a helper that hangs on invalid
    // input is a helper that eventually meets some.
    expect(WeekdayMask::nextDates(0, Carbon::parse('2026-06-01'), 10))->toBe([]);
})->group('fast');
