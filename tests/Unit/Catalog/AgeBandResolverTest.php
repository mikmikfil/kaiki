<?php

declare(strict_types=1);

use App\Domain\Catalog\Support\AgeBandResolver;
use App\Models\AgeBand;

/*
|--------------------------------------------------------------------------
| Which band a passenger falls into — spec CAT-7, AVL-23, PRC-7
|--------------------------------------------------------------------------
|
| Pricing asks this what to charge, the availability engine asks whether a
| passenger consumes a seat, and the manifest asks what to print beside a name.
| One answer in one place, so those three cannot disagree about a two-year-old.
|
| Boundaries are tested on both sides, because "0–2" and "3–11" is how an
| operator writes it and an off-by-one puts a three-year-old on a lap.
|
| The bands here are **unsaved models** — the resolver takes a collection and
| never touches a database, which is what lets M2 run it against a snapshot
| frozen at booking time.
|
*/

function band(string $code, int $min, ?int $max, bool $counts = true, bool $base = false): AgeBand
{
    return new AgeBand([
        'code' => $code,
        'min_age' => $min,
        'max_age' => $max,
        'counts_toward_capacity' => $counts,
        'is_base' => $base,
    ]);
}

/** @return list<AgeBand> */
function standardBands(): array
{
    return [
        band('infant', 0, 2, counts: false),
        band('child', 3, 11),
        band('adult', 12, null, base: true),
    ];
}

it('resolves an age inside a band', function (): void {
    expect(AgeBandResolver::forAge(standardBands(), 7)?->code)->toBe('child');
})->group('fast');

it('treats both bounds as inclusive', function (): void {
    // The whole rule. A child band of 3–11 covers a three-year-old and an
    // eleven-year-old; getting either end wrong charges a family the wrong fare
    // and, for the infant band, seats a child that should be on a lap.
    expect(AgeBandResolver::forAge(standardBands(), 3)?->code)->toBe('child')
        ->and(AgeBandResolver::forAge(standardBands(), 11)?->code)->toBe('child');
})->group('fast');

it('lands on the neighbouring band one either side of a boundary', function (): void {
    expect(AgeBandResolver::forAge(standardBands(), 2)?->code)->toBe('infant')
        ->and(AgeBandResolver::forAge(standardBands(), 12)?->code)->toBe('adult');
})->group('fast');

it('treats a null upper bound as no upper bound', function (): void {
    // The adult band almost always has one — the alternative is picking an
    // arbitrary 120 that eventually excludes somebody.
    expect(AgeBandResolver::forAge(standardBands(), 45)?->code)->toBe('adult')
        ->and(AgeBandResolver::forAge(standardBands(), 103)?->code)->toBe('adult');
})->group('fast');

it('resolves the lowest age a product covers', function (): void {
    expect(AgeBandResolver::forAge(standardBands(), 0)?->code)->toBe('infant');
})->group('fast');

it('returns null for an age no band covers', function (): void {
    // A real answer, not a failure: a product sold to adults only has nothing
    // to charge a five-year-old, and the booking form must say so rather than
    // guess a fare.
    $adultsOnly = [band('adult', 18, null, base: true)];

    expect(AgeBandResolver::forAge($adultsOnly, 5))->toBeNull();
})->group('fast');

it('takes the narrowest band when a set somehow overlaps', function (): void {
    // CAT-8 forbids overlap, so a valid set never reaches this. It exists for
    // the set that slipped through — a row written around the Action, or an
    // import from an older version — so the answer is deterministic rather than
    // decided by row order. "0–2" beating "0–99" is what a human would say.
    $overlapping = [
        band('everyone', 0, 99),
        band('infant', 0, 2, counts: false),
    ];

    expect(AgeBandResolver::forAge($overlapping, 1)?->code)->toBe('infant')
        ->and(AgeBandResolver::forAge(array_reverse($overlapping), 1)?->code)->toBe('infant');
})->group('fast');

it('finds the base band that multipliers anchor to', function (): void {
    expect(AgeBandResolver::base(standardBands())?->code)->toBe('adult');
})->group('fast');

it('counts only the bands that consume a seat', function (): void {
    // AVL-23, and the reason the flag exists. Two adults, two children and two
    // infants is six people aboard and four seats sold.
    $pax = ['adult' => 2, 'child' => 2, 'infant' => 2];

    expect(AgeBandResolver::countedSeats(standardBands(), $pax))->toBe(4);
})->group('fast');

it('counts every person aboard for the legal capacity check', function (): void {
    // `vessels.capacity_max` is a certificate: an infant on a lap is a person
    // on the boat whether or not they occupy a seat. Deliberately ignores the
    // flag, which is the difference between this and `countedSeats`.
    expect(AgeBandResolver::totalPersons(['adult' => 2, 'child' => 2, 'infant' => 2]))->toBe(6);
})->group('fast');

it('ignores a pax count for a band the product does not have', function (): void {
    // A stale code in a payload — an old widget, or a band deleted since —
    // must not inflate the seat count.
    expect(AgeBandResolver::countedSeats(standardBands(), ['adult' => 2, 'ghost' => 5]))->toBe(2);
})->group('fast');

it('treats a negative pax count as zero rather than a discount', function (): void {
    // The widget posts these. A negative that reached the seat maths would sell
    // capacity the boat does not have.
    expect(AgeBandResolver::countedSeats(standardBands(), ['adult' => 2, 'child' => -3]))->toBe(2)
        ->and(AgeBandResolver::totalPersons(['adult' => 2, 'child' => -3]))->toBe(2);
})->group('fast');

it('never touches the database', function (): void {
    // The same property that makes RefundCalculator safe: it takes values, so
    // M2 can run it against a band snapshot frozen at booking time, and editing
    // a product's bands cannot change what an existing booking was charged.
    $reflection = new ReflectionClass(AgeBandResolver::class);

    expect($reflection->getConstructor())->toBeNull()
        ->and($reflection->getProperties())->toBe([]);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue("{$method->getName()} is not static");
    }
})->group('fast');
