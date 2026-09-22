<?php

declare(strict_types=1);

use App\Data\Availability\DepartureAvailabilityData;
use App\Enums\AvailabilityRejection;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AvailabilityScenarioBuilder;

/*
|--------------------------------------------------------------------------
| AVL-26b — «Χρειάζεται συνοδό ενήλικα», finally asked at the right moment
|--------------------------------------------------------------------------
|
| `age_bands.requires_adult` has been on the trip form since the catalogue was
| built, and until 2026-09-22 it was read in exactly one place: when an operator
| *removed* guests from a booking that already existed. Nothing asked it when
| the booking was made. Two children and no adult could be chosen, priced, paid
| for and confirmed, and the operator discovered it at the passerelle.
|
| The party rules are the ones with a remedy a guest can act on, so this is a
| rejection code of its own rather than a variant of `no_counted_pax`: two
| children **do** take seats, so the infants-alone rule never sees them.
|
*/

function escortScenario(callable $callback): mixed
{
    Queue::fake();
    Carbon::setTestNow('2026-06-01 08:00:00');

    $result = Tenancy::forTenant(Tenant::factory()->create(['timezone' => 'Europe/Athens']), $callback);

    Carbon::setTestNow();

    return $result;
}

/**
 * The builder's product plus a child band — 3–11, half price, takes a seat,
 * and needs an adult. Deliberately not added to the builder itself: every
 * existing AVL-23 and AVL-25 case counts seats against a fixture of exactly two
 * bands, and a third would change arithmetic those tests assert on.
 */
function escortProduct(AvailabilityScenarioBuilder $scenario): Product
{
    $product = $scenario->product();

    AgeBand::factory()->child()->create(['product_id' => $product->getKey()]);

    return $product->load('ageBands');
}

/** @param array<string, int> $pax */
function escortCheck(array $pax, ?callable $extraBands = null): ?DepartureAvailabilityData
{
    $scenario = AvailabilityScenarioBuilder::make()->onVessel(certificate: 20)->sellingSeats(12);
    $product = escortProduct($scenario);

    if ($extraBands !== null) {
        $extraBands($product);
        $product->load('ageBands');
    }

    $scenario->departure($product, '2026-07-04', '09:00');

    return $scenario->checkSeats($product, '2026-07-04', pax: $pax)[0]->departures[0] ?? null;
}

it('AVL-26b: two children with no adult are refused', function (): void {
    escortScenario(function (): void {
        // The bug this was written for. Two seats, both payable, a departure
        // with twelve free — everything the old engine looked at said yes.
        $line = escortCheck(['child' => 2]);

        expect($line?->available)->toBeFalse()
            ->and($line?->rejection)->toBe(AvailabilityRejection::NeedsAdult);
    });
})->group('fast');

it('AVL-26b: one adult with the same two children is fine', function (): void {
    escortScenario(function (): void {
        expect(escortCheck(['adult' => 1, 'child' => 2])?->available)->toBeTrue();
    });
})->group('fast');

it('AVL-26b: a child alone with an infant is still refused', function (): void {
    escortScenario(function (): void {
        // A lap is not an escort. The infant adds a person and no adult, so the
        // party is exactly as unaccompanied as it was.
        expect(escortCheck(['child' => 1, 'infant' => 1])?->rejection)
            ->toBe(AvailabilityRejection::NeedsAdult);
    });
})->group('fast');

it('AVL-26: infants alone keep their own code, not this one', function (): void {
    escortScenario(function (): void {
        // Both rules refuse this party, and the order is the assertion: an
        // infant band also carries `requires_adult`, so a naive ordering would
        // report `needs_adult` for a party that has nobody in a seat at all.
        expect(escortCheck(['infant' => 2])?->rejection)
            ->toBe(AvailabilityRejection::NoCountedPax);
    });
})->group('fast');

it('AVL-26b: the escort does not have to be the base band', function (): void {
    escortScenario(function (): void {
        // A grandmother on a separately priced «Άνω των 65» fare is an adult.
        // The old operator-side rule counted only `is_base` and would have
        // refused her; the shared predicate asks whether the band needs an
        // adult itself, which is the question that was always meant.
        $line = escortCheck(['senior' => 1, 'child' => 1], static function (Product $product): void {
            AgeBand::factory()->fixedPrice()->create([
                'product_id' => $product->getKey(),
                'counts_toward_capacity' => true,
                'requires_adult' => false,
            ]);
        });

        expect($line?->available)->toBeTrue();
    });
})->group('fast');

it('AVL-26b: an empty party is a calendar asking what exists', function (): void {
    escortScenario(function (): void {
        // No party, no judgement — the range read that paints a month must not
        // start refusing dates because nobody has chosen passengers yet.
        expect(escortCheck([])?->available)->toBeTrue();
    });
})->group('fast');
