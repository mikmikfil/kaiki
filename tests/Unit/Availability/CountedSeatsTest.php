<?php

declare(strict_types=1);

use App\Domain\Availability\Support\CountedSeats;
use App\Models\AgeBand;

/*
|--------------------------------------------------------------------------
| Seats versus people — spec AVL-23, AVL-25, AVL-26
|--------------------------------------------------------------------------
|
| A family of two adults and two infants on laps is **two seats and four
| people**, and the whole of AVL-25 exists because those two numbers get
| conflated. Using the seat count for the legal check lets a boat sail illegally
| full of infants; using the head count for the commercial check refuses a
| booking the operator wanted.
|
| Unmigrated models: this is arithmetic over a set of bands, and a database
| would only slow it down.
|
*/

/** @return list<AgeBand> adult (counts), child (counts), infant (does not) */
function bandSet(): array
{
    $adult = new AgeBand(['code' => 'adult', 'counts_toward_capacity' => true]);
    $child = new AgeBand(['code' => 'child', 'counts_toward_capacity' => true]);
    $infant = new AgeBand(['code' => 'infant', 'counts_toward_capacity' => false]);

    return [$adult, $child, $infant];
}

it('counts only the bands that take a seat', function (): void {
    expect(CountedSeats::counted(bandSet(), ['adult' => 2, 'child' => 1, 'infant' => 2]))->toBe(3);
})->group('fast');

it('counts every person for the legal check', function (): void {
    // The number AVL-25 compares against the certificate.
    expect(CountedSeats::totalPersons(['adult' => 2, 'child' => 1, 'infant' => 2]))->toBe(5);
})->group('fast');

it('reports the two numbers differently for the same party', function (): void {
    // The case the spec calls out by name.
    $party = ['adult' => 2, 'infant' => 2];

    expect(CountedSeats::counted(bandSet(), $party))->toBe(2)
        ->and(CountedSeats::totalPersons($party))->toBe(4);
})->group('fast');

it('refuses a party of infants alone', function (): void {
    // AVL-26. An infant travels on a lap, and the lap has to belong to
    // somebody. Reported with its own code because the remedy is to add an
    // adult, not to pick another date.
    expect(CountedSeats::hasCountedPax(bandSet(), ['infant' => 2]))->toBeFalse()
        ->and(CountedSeats::hasCountedPax(bandSet(), ['adult' => 1, 'infant' => 2]))->toBeTrue();
})->group('fast');

it('drops a band code the product does not have', function (): void {
    // The widget runs on somebody else's page and can post anything. An unknown
    // code that counted would be seats sold against a band that does not exist.
    expect(CountedSeats::sanitise(bandSet(), ['adult' => 2, 'dog' => 3]))
        ->toBe(['adult' => 2]);
})->group('fast');

it('floors a negative count at zero', function (): void {
    // The shape of a free-seat exploit: a negative infant count reducing the
    // size of a party.
    expect(CountedSeats::sanitise(bandSet(), ['adult' => 2, 'infant' => -5]))
        ->toBe(['adult' => 2, 'infant' => 0])
        ->and(CountedSeats::totalPersons(['adult' => 2, 'infant' => -5]))->toBe(2);
})->group('fast');
