<?php

declare(strict_types=1);

use App\Domain\Pricing\Support\SeasonCandidateResolver;
use App\Models\Season;
use App\Models\SeasonDateRange;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Season resolution order — spec PRC-3, PRC-4
|--------------------------------------------------------------------------
|
| PRC-4 makes a priority tie a **save-time refusal**; this ordering is defence
| in depth for rows that arrived another way — an import, a direct edit, a row
| written before the validation existed. *"The engine must never depend on
| database row order."*
|
| Three steps, and the middle one is the one `docs/data-model.md` omitted:
| priority DESC, then narrowest matching range ASC, then id ASC.
|
| These seasons are built in memory with their ranges attached, so the ordering
| is tested apart from the query that finds them.
|
*/

/** @param list<array{0: string, 1: string}> $ranges start and end date, inclusive */
function seasonWith(int $id, int $priority, array $ranges): Season
{
    $season = new Season(['priority' => $priority]);
    $season->id = $id;
    $season->exists = true;

    $season->setRelation('dateRanges', collect(array_map(
        static function (array $range): SeasonDateRange {
            return new SeasonDateRange(['starts_on' => $range[0], 'ends_on' => $range[1]]);
        },
        $ranges,
    )));

    return $season;
}

function on(string $date): Carbon
{
    return Carbon::parse($date);
}

it('contains a date on the first and last day of a range', function (): void {
    // Both bounds inclusive: a season is "1 June to 15 September" and a
    // departure on either day is in it. Storing this as a UTC timestamp would
    // move both edges by three hours.
    $season = seasonWith(1, 10, [['2026-06-01', '2026-09-15']]);

    expect($season->contains(on('2026-06-01')))->toBeTrue()
        ->and($season->contains(on('2026-09-15')))->toBeTrue();
})->group('fast');

it('excludes the day either side of a range', function (): void {
    $season = seasonWith(1, 10, [['2026-06-01', '2026-09-15']]);

    expect($season->contains(on('2026-05-31')))->toBeFalse()
        ->and($season->contains(on('2026-09-16')))->toBeFalse();
})->group('fast');

it('measures a one-day range as one day, not zero', function (): void {
    // Inclusive length. A zero here would make a single-day season the
    // narrowest possible match by accident rather than by meaning.
    $range = new SeasonDateRange(['starts_on' => '2026-08-15', 'ends_on' => '2026-08-15']);

    expect($range->lengthInDays())->toBe(1);
})->group('fast');

it('puts higher priority first', function (): void {
    // PRC-4 step 1, and how "August" sits inside "Summer" and wins.
    $summer = seasonWith(1, 10, [['2026-06-01', '2026-09-15']]);
    $august = seasonWith(2, 20, [['2026-08-01', '2026-08-31']]);

    $ordered = SeasonCandidateResolver::order(collect([$summer, $august]), on('2026-08-15'));

    expect($ordered->first()?->getKey())->toBe(2);
})->group('fast');

it('breaks a priority tie on the narrowest matching range', function (): void {
    // PRC-4 step 2, which `docs/data-model.md` omitted — its note went straight
    // from priority to id. The narrower statement is the more specific one,
    // which is what an operator means by writing it.
    $wide = seasonWith(1, 10, [['2026-06-01', '2026-09-15']]);
    $narrow = seasonWith(2, 10, [['2026-08-10', '2026-08-20']]);

    $ordered = SeasonCandidateResolver::order(collect([$wide, $narrow]), on('2026-08-15'));

    expect($ordered->first()?->getKey())->toBe(2);
})->group('fast');

it('falls back to the lowest id when priority and width both tie', function (): void {
    // PRC-4 step 3. The last resort, and the reason the whole chain is
    // deterministic rather than dependent on row order.
    $second = seasonWith(9, 10, [['2026-08-01', '2026-08-31']]);
    $first = seasonWith(4, 10, [['2026-08-01', '2026-08-31']]);

    $ordered = SeasonCandidateResolver::order(collect([$second, $first]), on('2026-08-15'));

    expect($ordered->first()?->getKey())->toBe(4);
})->group('fast');

it('gives the same answer whatever order the candidates arrive in', function (): void {
    // The property that matters: PRC-4 says the engine must never depend on
    // database row order, so the same set shuffled must resolve identically.
    $a = seasonWith(1, 10, [['2026-06-01', '2026-09-15']]);
    $b = seasonWith(2, 10, [['2026-08-10', '2026-08-20']]);
    $c = seasonWith(3, 20, [['2026-08-14', '2026-08-16']]);

    $forwards = SeasonCandidateResolver::order(collect([$a, $b, $c]), on('2026-08-15'));
    $backwards = SeasonCandidateResolver::order(collect([$c, $b, $a]), on('2026-08-15'));

    expect($forwards->pluck('id')->all())->toBe([3, 2, 1])
        ->and($backwards->pluck('id')->all())->toBe([3, 2, 1]);
})->group('fast');

it('uses a season narrowest matching range, not its narrowest range', function (): void {
    // A season with a two-day range in March and a four-month range in summer
    // is a wide season *for an August date*. Measuring its narrowest range
    // overall would let an unrelated short range win a tie-break.
    $season = seasonWith(1, 10, [['2026-03-01', '2026-03-02'], ['2026-06-01', '2026-09-15']]);

    expect($season->narrowestMatchingRangeDays(on('2026-08-15')))->toBe(107)
        ->and($season->narrowestMatchingRangeDays(on('2026-03-01')))->toBe(2);
})->group('fast');

it('reports no matching range for a date the season does not cover', function (): void {
    $season = seasonWith(1, 10, [['2026-06-01', '2026-09-15']]);

    expect($season->narrowestMatchingRangeDays(on('2026-01-01')))->toBeNull();
})->group('fast');

it('keeps a non-matching season last rather than throwing', function (): void {
    // `order()` is reachable with a set the caller assembled, so a season that
    // does not match must sort last rather than blow up mid-quote.
    $matching = seasonWith(1, 10, [['2026-06-01', '2026-09-15']]);
    $notMatching = seasonWith(2, 10, [['2026-01-01', '2026-01-31']]);

    $ordered = SeasonCandidateResolver::order(collect([$notMatching, $matching]), on('2026-08-15'));

    expect($ordered->first()?->getKey())->toBe(1);
})->group('fast');
