<?php

declare(strict_types=1);

use App\Domain\Availability\Support\LocalDay;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| A local day is not always 24 hours — spec AVL-14
|--------------------------------------------------------------------------
|
| In Europe/Athens the last Sunday of March is **23 hours** and the last Sunday
| of October is **25**. Code that adds 86400 seconds to a day's start is wrong
| twice a year in opposite directions, on the two days most likely to carry an
| odd departure — and the symptom is a trip that vanishes from, or appears
| twice in, an availability response.
|
| All three lengths are asserted, because a test with only the ordinary day
| passes 363 days a year.
|
*/

it('measures a day as 23, 24 or 25 hours', function (string $date, int $hours): void {
    expect(LocalDay::of($date, 'Europe/Athens')->hours())->toBe($hours);
})->with([
    'ordinary summer day' => ['2026-07-04', 24],
    'ordinary winter day' => ['2026-01-15', 24],
    'spring forward' => ['2026-03-29', 23],
    'autumn fall back' => ['2026-10-25', 25],
])->group('fast');

it('starts and ends at local midnight, expressed in UTC', function (): void {
    $day = LocalDay::of('2026-07-04', 'Europe/Athens');

    expect($day->startUtc->toDateTimeString())->toBe('2026-07-03 21:00:00')
        ->and($day->endUtcExclusive->toDateTimeString())->toBe('2026-07-04 21:00:00');
})->group('fast');

it('is half-open, so consecutive days neither gap nor overlap', function (): void {
    // The reason for a half-open interval rather than an inclusive end: `>=
    // start && < end` composes across days with no "last microsecond" to
    // invent, and nothing lands in two days at once.
    $first = LocalDay::of('2026-07-04', 'Europe/Athens');
    $second = LocalDay::of('2026-07-05', 'Europe/Athens');

    expect($first->endUtcExclusive->equalTo($second->startUtc))->toBeTrue()
        ->and($first->contains($first->endUtcExclusive))->toBeFalse()
        ->and($second->contains($second->startUtc))->toBeTrue();
})->group('fast');

it('contains the instants that fall inside it', function (): void {
    $day = LocalDay::of('2026-07-04', 'Europe/Athens');

    expect($day->contains(Carbon::parse('2026-07-04 06:00:00', 'UTC')))->toBeTrue()
        ->and($day->contains(Carbon::parse('2026-07-03 20:59:59', 'UTC')))->toBeFalse();
})->group('fast');

it('counts a window as overlapping only while it is still running', function (): void {
    // A departure that ends exactly at midnight belongs to the day it started
    // in, not to the next one — otherwise every evening cruise would appear on
    // two days of the calendar.
    $day = LocalDay::of('2026-07-05', 'Europe/Athens');

    expect($day->overlaps(
        Carbon::parse('2026-07-04 18:00:00', 'UTC'),
        Carbon::parse('2026-07-04 21:00:00', 'UTC'),
    ))->toBeFalse()
        ->and($day->overlaps(
            Carbon::parse('2026-07-04 18:00:00', 'UTC'),
            Carbon::parse('2026-07-04 22:00:00', 'UTC'),
        ))->toBeTrue();
})->group('fast');

it('measures a day in a timezone other than the Greek one', function (): void {
    expect(LocalDay::of('2026-03-29', 'Europe/London')->hours())->toBe(23)
        ->and(LocalDay::of('2026-03-29', 'UTC')->hours())->toBe(24);
})->group('fast');
