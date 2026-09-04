<?php

declare(strict_types=1);

use App\Data\Availability\DepartureAvailabilityData;
use App\Enums\AvailabilityRejection;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AvailabilityScenarioBuilder;

/*
|--------------------------------------------------------------------------
| AVL-23 and AVL-25 — two checks that must be able to disagree
|--------------------------------------------------------------------------
|
| The spec resolves AVL-25 with its reasoning attached: *"conflating them would
| let a boat sail illegally full of infants."*
|
| A party of two adults and one infant is **two seats and three people**. The
| commercial check compares seats against what is left; the legal check compares
| people against the boat's certificate. The case that proves they are
| independent is the one where **one passes and the other fails** — and it must
| work in both directions, or one of them is doing nothing.
|
*/

function bandScenario(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-06-01 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

/** @param array<string, int> $pax */
function bandCheck(int $certificate, int $capacity, int $seatsSold, array $pax): ?DepartureAvailabilityData
{
    $scenario = AvailabilityScenarioBuilder::make()->onVessel(certificate: $certificate)->sellingSeats($capacity);
    $product = $scenario->product();

    $scenario->departure($product, '2026-07-04', '09:00', seatsSold: $seatsSold);

    return $scenario->checkSeats($product, '2026-07-04', pax: $pax)[0]->departures[0] ?? null;
}

it('AVL-23: an infant consumes no seat', function (): void {
    bandScenario(function (): void {
        // Two adults and one infant on a departure with exactly two seats left.
        // The seat check sees two, and passes.
        expect(bandCheck(certificate: 12, capacity: 10, seatsSold: 8, pax: ['adult' => 2, 'infant' => 1])?->available)
            ->toBeTrue();
    });
})->group('fast');

it('AVL-25: the same party fails the certificate when the boat is legally full', function (): void {
    bandScenario(function (): void {
        // The independence case, and the one the spec was written for. Two
        // seats free, so the seat check passes; the certificate is 10 and ten
        // people are already aboard in eight seats plus this party's three, so
        // the legal check fails.
        //
        // `DeparturePersonsAboard` reports zero until M2, so the party alone is
        // measured against the certificate — three people against a boat
        // licensed for two.
        $line = bandCheck(certificate: 2, capacity: 10, seatsSold: 8, pax: ['adult' => 2, 'infant' => 1]);

        expect($line?->available)->toBeFalse()
            // Not `NotEnoughSeats`: there are two seats free, and saying the
            // boat is full would be false.
            ->and($line?->rejection)->toBe(AvailabilityRejection::LegalCapacityExceeded);
    });
})->group('fast');

it('AVL-23: the seat check fails while the legal one would pass', function (): void {
    bandScenario(function (): void {
        // The mirror image, which proves the seat check is doing work of its
        // own. Three people on a boat licensed for twelve is legally fine, and
        // there is only one seat left.
        $line = bandCheck(certificate: 12, capacity: 10, seatsSold: 9, pax: ['adult' => 2, 'infant' => 1]);

        expect($line?->rejection)->toBe(AvailabilityRejection::NotEnoughSeats);
    });
})->group('fast');

it('AVL-25: a party exactly at the certificate is accepted', function (): void {
    bandScenario(function (): void {
        // Twelve people on a boat licensed for twelve is a full boat, not an
        // overloaded one — and a bound tested only from one side is a bound
        // that can be off by one.
        expect(bandCheck(certificate: 12, capacity: 12, seatsSold: 0, pax: ['adult' => 10, 'infant' => 2])?->available)
            ->toBeTrue();
    });
})->group('fast');

it('AVL-25: one person over the certificate is refused', function (): void {
    bandScenario(function (): void {
        expect(bandCheck(certificate: 12, capacity: 20, seatsSold: 0, pax: ['adult' => 10, 'infant' => 3])?->rejection)
            ->toBe(AvailabilityRejection::LegalCapacityExceeded);
    });
})->group('fast');

it('AVL-26: a party of infants alone is refused with its own code', function (): void {
    bandScenario(function (): void {
        // An infant travels on a lap and the lap has to belong to somebody. Its
        // own code because the remedy — add an adult — belongs to no other
        // reason.
        expect(bandCheck(certificate: 12, capacity: 10, seatsSold: 0, pax: ['infant' => 2])?->rejection)
            ->toBe(AvailabilityRejection::NoCountedPax);
    });
})->group('fast');

it('AVL-7: an infant band priced at zero is still a person and still priced', function (): void {
    bandScenario(function (): void {
        // AVL-7's companion rule, PRC-7: not counting toward capacity does not
        // imply free. The infant band's multiplier is the operator's choice,
        // and conflating the two would stop charging for any band they decided
        // not to count.
        $scenario = AvailabilityScenarioBuilder::make();
        $product = $scenario->product();

        $infant = $product->ageBands()->where('code', 'infant')->firstOrFail();

        expect($infant->counts_toward_capacity)->toBeFalse()
            ->and($infant->price_multiplier_bp)->toBe(0);
    });
})->group('fast');
