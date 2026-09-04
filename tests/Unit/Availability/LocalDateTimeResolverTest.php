<?php

declare(strict_types=1);

use App\Domain\Availability\LocalDateTimeResolver;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Local time to UTC — ADR-0016, spec AVL-15 to AVL-18, CNV-2, CNV-3
|--------------------------------------------------------------------------
|
| Every timezone bug this product will ever have traces back to this class, and
| PHP gets both DST edges wrong for our purposes without saying a word:
|
| - `new DateTimeImmutable('2026-03-29 03:30', Athens)` returns **04:30**. That
|   is ADR-0016's Option B — the option rejected because it silently moves a
|   departure a guest has booked.
| - `new DateTimeImmutable('2026-10-25 03:30', Athens)` returns the **second**
|   occurrence. ADR-0016 fixes the tie-break as the first, the one still in
|   summer time.
|
| Both are asserted here by naming the calendar date, because a test that only
| covered ordinary days would pass on every day of the year except the two that
| matter.
|
*/

const ATHENS = 'Europe/Athens';

it('converts an ordinary local time', function (string $date, string $time, string $expectedUtc): void {
    $resolved = LocalDateTimeResolver::resolve($date, $time, ATHENS);

    expect($resolved->existent)->toBeTrue()
        ->and($resolved->ambiguous)->toBeFalse()
        ->and($resolved->instantOrFail()->toDateTimeString())->toBe($expectedUtc);
})->with([
    // Summer, EEST (+3).
    'July morning' => ['2026-07-04', '09:00', '2026-07-04 06:00:00'],
    // Winter, EET (+2).
    'January morning' => ['2026-01-15', '09:00', '2026-01-15 07:00:00'],
    // Either side of each transition, on the transition day itself.
    'before spring forward' => ['2026-03-29', '02:30', '2026-03-29 00:30:00'],
    'after spring forward' => ['2026-03-29', '05:00', '2026-03-29 02:00:00'],
    'before autumn fall back' => ['2026-10-25', '02:30', '2026-10-24 23:30:00'],
    'after autumn fall back' => ['2026-10-25', '05:00', '2026-10-25 03:00:00'],
])->group('fast');

it('refuses a local time that does not exist on the spring-forward date', function (string $time): void {
    // 29 March 2026: 03:00 becomes 04:00, so 03:00–03:59 never happens.
    // ADR-0016 Option A: no departure is invented, and the operator is told.
    $resolved = LocalDateTimeResolver::resolve('2026-03-29', $time, ATHENS);

    expect($resolved->existent)->toBeFalse()
        ->and($resolved->instant)->toBeNull()
        ->and($resolved->ambiguous)->toBeFalse();
})->with(['03:00', '03:30', '03:59'])->group('fast');

it('throws rather than inventing an instant for a caller who did not check', function (): void {
    LocalDateTimeResolver::resolve('2026-03-29', '03:30', ATHENS)->instantOrFail();
})->throws(LogicException::class)->group('fast');

it('takes the first occurrence of an ambiguous local time', function (string $time, string $expectedUtc): void {
    // 25 October 2026: 04:00 becomes 03:00, so 03:00–03:59 happens twice.
    // ADR-0016 fixes the tie-break as the **earlier** instant, the one still at
    // +03:00 — and PHP's own answer is the later one, which is the whole reason
    // this class subtracts an hour of absolute time.
    $resolved = LocalDateTimeResolver::resolve('2026-10-25', $time, ATHENS);

    expect($resolved->existent)->toBeTrue()
        ->and($resolved->ambiguous)->toBeTrue()
        ->and($resolved->instantOrFail()->toDateTimeString())->toBe($expectedUtc);
})->with([
    ['03:00', '2026-10-25 00:00:00'],
    ['03:30', '2026-10-25 00:30:00'],
    ['03:59', '2026-10-25 00:59:00'],
])->group('fast');

it('renders an ambiguous instant back to the local time asked for', function (): void {
    // The round trip has to hold, or the model's CNV-3 guard would refuse every
    // departure on the fall-back date.
    $resolved = LocalDateTimeResolver::resolve('2026-10-25', '03:30', ATHENS);
    $instant = $resolved->instantOrFail();

    expect(LocalDateTimeResolver::localDate($instant, ATHENS))->toBe('2026-10-25')
        ->and(LocalDateTimeResolver::localTime($instant, ATHENS))->toBe('03:30:00');
})->group('fast');

it('adds a duration in absolute elapsed minutes, not wall clock', function (string $date, string $time, int $minutes, string $expectedLocalEnd): void {
    // AVL-17. A four-hour cruise is four hours of sea time whatever the clock
    // does, which is what the crew and the vessel schedule care about — so it
    // ends at a *different wall-clock* hour across a transition.
    $starts = LocalDateTimeResolver::resolve($date, $time, ATHENS)->instantOrFail();
    $ends = LocalDateTimeResolver::endsAt($starts, $minutes);

    expect(LocalDateTimeResolver::localTime($ends, ATHENS))->toBe($expectedLocalEnd)
        // Absolute elapsed minutes, always.
        ->and($ends->getTimestamp() - $starts->getTimestamp())->toBe($minutes * 60);
})->with([
    // Ordinary day: four hours of sea time, four hours on the clock.
    'ordinary' => ['2026-07-04', '09:00', 240, '13:00:00'],
    // Spring forward: starts 01:00, four hours later the clock says 06:00,
    // because an hour of wall clock vanished in between.
    'across spring forward' => ['2026-03-29', '01:00', 240, '06:00:00'],
    // Autumn: starts 02:00, four hours later the clock says 05:00, because an
    // hour happened twice.
    'across autumn fall back' => ['2026-10-25', '02:00', 240, '05:00:00'],
])->group('fast');

it('converts in a timezone other than the Greek one', function (): void {
    // ENV-14: nothing here may assume Europe/Athens, or the first non-Greek
    // operator finds out the hard way.
    $resolved = LocalDateTimeResolver::resolve('2026-07-04', '09:00', 'Europe/London');

    expect($resolved->instantOrFail()->toDateTimeString())->toBe('2026-07-04 08:00:00');
})->group('fast');

it('accepts a time with or without seconds', function (): void {
    // `HH:MM` arrives from a form and `HH:MM:SS` from the database. They are
    // the same time, and a resolver that disagreed would refuse every departure
    // it had just written.
    expect(LocalDateTimeResolver::resolve('2026-07-04', '09:00', ATHENS)->instantOrFail()->toDateTimeString())
        ->toBe(LocalDateTimeResolver::resolve('2026-07-04', '09:00:00', ATHENS)->instantOrFail()->toDateTimeString());
})->group('fast');

it('takes a date object as readily as a string', function (): void {
    expect(LocalDateTimeResolver::resolve(Carbon::parse('2026-07-04'), '09:00', ATHENS)->instantOrFail()->toDateTimeString())
        ->toBe('2026-07-04 06:00:00');
})->group('fast');

it('always answers in UTC, whatever the machine clock is set to', function (): void {
    // ENV-14 again, from the other side: the CI job runs this group under a
    // third timezone, and a resolver that returned a zoned object would make
    // every downstream comparison depend on the server's own configuration.
    expect(LocalDateTimeResolver::resolve('2026-07-04', '09:00', ATHENS)->instantOrFail()->getTimezone()->getName())
        ->toBe('UTC');
})->group('fast');
