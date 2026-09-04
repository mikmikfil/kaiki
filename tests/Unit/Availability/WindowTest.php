<?php

declare(strict_types=1);

use App\Domain\Availability\Support\Window;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The AVL-7 conflict predicate — spec AVL-7, TST-5
|--------------------------------------------------------------------------
|
| Fixed by the spec:
|
|     A.start < B.end + buffer  and  B.start < A.end + buffer
|
| symmetric, and the buffer counted **once**. The obvious mistake is to pad both
| windows, which doubles the required gap and makes a boat look busy for two
| hours between two one-hour-buffered trips — and the operator then rings
| support about departures that will not generate.
|
| TST-5 asks for the boundary and one minute either side, so that is what is
| here: a gap exactly equal to the buffer is legal, one minute less is not.
|
*/

function windowAt(string $start, string $end): Window
{
    return Window::of(Carbon::parse($start, 'UTC'), Carbon::parse($end, 'UTC'));
}

it('treats a gap equal to the buffer as legal', function (): void {
    // The boat has had exactly its turnaround. Refusing this would make the
    // buffer mean "more than", which is not what AVL-7 says.
    $morning = windowAt('2026-07-04 06:00', '2026-07-04 10:00');
    $afternoon = windowAt('2026-07-04 11:00', '2026-07-04 15:00');

    expect($morning->conflictsWith($afternoon, 60))->toBeFalse()
        ->and($afternoon->conflictsWith($morning, 60))->toBeFalse();
})->group('fast');

it('treats one minute less than the buffer as a conflict', function (): void {
    $morning = windowAt('2026-07-04 06:00', '2026-07-04 10:00');
    $afternoon = windowAt('2026-07-04 10:59', '2026-07-04 15:00');

    expect($morning->conflictsWith($afternoon, 60))->toBeTrue()
        ->and($afternoon->conflictsWith($morning, 60))->toBeTrue();
})->group('fast');

it('counts the buffer once, not once per side', function (): void {
    // The bug this test exists for: padding both windows would require a
    // two-hour gap between two one-hour-buffered trips, and a 90-minute gap
    // would read as a conflict when the spec says it is not.
    $morning = windowAt('2026-07-04 06:00', '2026-07-04 10:00');
    $afternoon = windowAt('2026-07-04 11:30', '2026-07-04 15:00');

    expect($morning->conflictsWith($afternoon, 60))->toBeFalse();
})->group('fast');

it('conflicts on a plain overlap whatever the buffer', function (): void {
    $first = windowAt('2026-07-04 06:00', '2026-07-04 10:00');
    $second = windowAt('2026-07-04 09:00', '2026-07-04 12:00');

    expect($first->conflictsWith($second, 0))->toBeTrue()
        ->and($first->overlaps($second))->toBeTrue();
})->group('fast');

it('does not conflict with a window that merely touches it, with no buffer', function (): void {
    // Half-open in effect: a trip ending at 10:00 and one starting at 10:00 are
    // back to back, and with no turnaround required that is legal.
    $first = windowAt('2026-07-04 06:00', '2026-07-04 10:00');
    $second = windowAt('2026-07-04 10:00', '2026-07-04 12:00');

    expect($first->overlaps($second))->toBeFalse();
})->group('fast');

it('is symmetric for every case', function (int $bufferMinutes, string $secondStart): void {
    $first = windowAt('2026-07-04 06:00', '2026-07-04 10:00');
    $second = windowAt($secondStart, '2026-07-04 20:00');

    expect($first->conflictsWith($second, $bufferMinutes))
        ->toBe($second->conflictsWith($first, $bufferMinutes));
})->with([
    [60, '2026-07-04 09:00'],
    [60, '2026-07-04 11:00'],
    [0, '2026-07-04 10:00'],
    [120, '2026-07-04 11:30'],
])->group('fast');

it('measures its own length in elapsed minutes', function (): void {
    expect(windowAt('2026-07-04 06:00', '2026-07-04 10:00')->minutes())->toBe(240);
})->group('fast');

it('pads only as a query filter, never as the answer', function (): void {
    // The padded window is used to narrow a database scan: it can only return
    // more candidates than needed, never fewer. Using it *as* the predicate
    // would be the double-counting bug above.
    $window = windowAt('2026-07-04 06:00', '2026-07-04 10:00');
    $padded = $window->paddedBy(60);

    expect($padded->startUtc->toDateTimeString())->toBe('2026-07-04 05:00:00')
        ->and($padded->endUtc->toDateTimeString())->toBe('2026-07-04 11:00:00');
})->group('fast');
