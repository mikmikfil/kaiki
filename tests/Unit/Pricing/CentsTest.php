<?php

declare(strict_types=1);

use App\Support\Money\Cents;

/*
|--------------------------------------------------------------------------
| Integer cents, half up, EUR — CNV-1, CNV-4
|--------------------------------------------------------------------------
|
| One rounding mode and one currency, decided here so they cannot be decided
| differently in the next file. The cases below are the ones a float would get
| wrong: `1.15 * 100` is 114.99999999999999, and a percentage of an odd cent
| lands exactly on a half.
|
*/

it('rounds half up rather than to even', function (int $cents, int $bp, int $expected): void {
    expect(Cents::applyBasisPoints($cents, $bp))->toBe($expected);
})->with([
    // 50% of 6501 is 3250.5 — half up gives 3251, banker's rounding gives 3250.
    [6501, 5000, 3251],
    // 50% of 6503 is 3251.5 — half up again, and the "round to even" answer
    // would differ in the other direction.
    [6503, 5000, 3252],
    [6500, 5000, 3250],
    [6500, 10000, 6500],
    [6500, 0, 0],
    [0, 5000, 0],
])->group('fast');

it('applies whole percentages the same way', function (): void {
    expect(Cents::applyPercent(15000, 30))->toBe(4500)
        ->and(Cents::applyPercent(3333, 30))->toBe(1000)
        ->and(Cents::applyPercent(1001, 33))->toBe(330);
})->group('fast');

it('splits a VAT-inclusive amount so the halves always sum to the total', function (int $gross, int $rateBp): void {
    // The property that matters, asserted for every case rather than the exact
    // net: an invoice whose lines do not sum to its total is a myDATA
    // rejection, and two independently rounded halves eventually do not.
    $net = Cents::netOfInclusive($gross, $rateBp);
    $vat = Cents::vatOfInclusive($gross, $rateBp);

    expect($net + $vat)->toBe($gross)
        ->and($net)->toBeInt()
        ->and($vat)->toBeInt();
})->with([
    [14250, 1300],
    [1, 1300],
    [999_99, 2400],
    [1234, 600],
    [0, 1300],
])->group('fast');

it('leaves an amount alone at a zero rate', function (): void {
    expect(Cents::netOfInclusive(14250, 0))->toBe(14250)
        ->and(Cents::vatOfInclusive(14250, 0))->toBe(0);
})->group('fast');

it('returns integers, never floats', function (): void {
    expect(Cents::applyBasisPoints(6501, 5000))->toBeInt()
        ->and(Cents::netOfInclusive(14250, 1300))->toBeInt();
})->group('fast');
