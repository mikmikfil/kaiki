<?php

declare(strict_types=1);

use App\Data\Pricing\PriceLineData;
use App\Domain\Catalog\Support\OfferedExtrasResolver;
use App\Domain\Pricing\Support\ExtraLineBuilder;
use App\Models\Extra;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Extras — spec PRC-9, PRC-10
|--------------------------------------------------------------------------
|
| Three pricing types and three different multiplications, and the one that is
| easy to get wrong is `per_person`: **counted** pax by default, **total**
| persons when the extra says so. A lunch and a lifejacket are consumed by the
| infant on a lap; a seat is not.
|
| `on_request` adds zero and is still recorded. PRC-9 is explicit that a booking
| containing one still confirms and pays the computed total — dropping the line
| would lose the guest's request, and pricing it would invent a figure nobody
| agreed to.
|
*/

function extraLineTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->create(), $callback);
}

/**
 * @param  array<int, int>  $quantities
 * @return list<PriceLineData>
 */
function extraLines(Product $product, array $quantities, int $countedPax, int $totalPax): array
{
    return ExtraLineBuilder::build(
        OfferedExtrasResolver::forProduct($product),
        $quantities,
        $countedPax,
        $totalPax,
    );
}

it('adds a per-booking extra once, however many people', function (): void {
    extraLineTenant(function (): void {
        $product = Product::factory()->create();
        $transfer = Extra::factory()->perBooking(4000)->tenantWide()->create();

        $lines = extraLines($product, [$transfer->getKey() => 1], countedPax: 6, totalPax: 8);

        expect($lines)->toHaveCount(1)
            ->and($lines[0]->totalCents)->toBe(4000);
    });
})->group('fast');

it('multiplies a per-person extra by counted pax by default', function (): void {
    extraLineTenant(function (): void {
        // A seat is what a lunch is usually sold against, and the infant on a
        // lap did not take one.
        $product = Product::factory()->create();
        $lunch = Extra::factory()->tenantWide()->create(['price_cents' => 1500]);

        $lines = extraLines($product, [$lunch->getKey() => 1], countedPax: 4, totalPax: 5);

        expect($lines[0]->totalCents)->toBe(6000);
    });
})->group('fast');

it('multiplies by total persons when the extra says so', function (): void {
    extraLineTenant(function (): void {
        // PRC-9's per-extra flag. The infant eats the lunch and wears the
        // lifejacket even though they consume no seat.
        $product = Product::factory()->create();
        $lunch = Extra::factory()->tenantWide()->pricesAllPax()->create(['price_cents' => 1500]);

        $lines = extraLines($product, [$lunch->getKey() => 1], countedPax: 4, totalPax: 5);

        expect($lines[0]->totalCents)->toBe(7500);
    });
})->group('fast');

it('records an on-request extra at zero rather than dropping or pricing it', function (): void {
    extraLineTenant(function (): void {
        $product = Product::factory()->create();
        $chef = Extra::factory()->tenantWide()->onRequest()->create();

        $lines = extraLines($product, [$chef->getKey() => 1], countedPax: 4, totalPax: 4);

        expect($lines)->toHaveCount(1)
            ->and($lines[0]->totalCents)->toBe(0)
            ->and($lines[0]->onRequest)->toBeTrue();
    });
})->group('fast');

it('refuses a quantity above max_qty', function (): void {
    extraLineTenant(function (): void {
        // PRC-10, server-side. The widget's own limit is a courtesy on somebody
        // else's page; this is the enforcement a hand-made request also hits.
        $product = Product::factory()->create();
        $extra = Extra::factory()->tenantWide()->limitedTo(2)->create();

        extraLines($product, [$extra->getKey() => 3], countedPax: 4, totalPax: 4);
    });
})->throws(ValidationException::class)->group('fast');

it('allows a quantity exactly at max_qty', function (): void {
    extraLineTenant(function (): void {
        $product = Product::factory()->create();
        $extra = Extra::factory()->tenantWide()->limitedTo(2)->create(['price_cents' => 1000]);

        $lines = extraLines($product, [$extra->getKey() => 2], countedPax: 3, totalPax: 3);

        expect($lines[0]->qty)->toBe(2)
            // Two lunches for each of three people.
            ->and($lines[0]->totalCents)->toBe(6000);
    });
})->group('fast');

it('leaves out an extra the guest did not choose', function (): void {
    extraLineTenant(function (): void {
        $product = Product::factory()->create();
        $extra = Extra::factory()->tenantWide()->create();

        expect(extraLines($product, [$extra->getKey() => 0], 4, 4))->toBe([]);
    });
})->group('fast');

it('uses the product override price rather than the extra price', function (): void {
    extraLineTenant(function (): void {
        $product = Product::factory()->create();
        $extra = Extra::factory()->create(['price_cents' => 1500]);

        $product->extras()->attach($extra->getKey(), ['price_cents_override' => 900, 'sort_order' => 0]);

        $lines = extraLines($product->refresh(), [$extra->getKey() => 1], countedPax: 2, totalPax: 2);

        expect($lines[0]->unitPriceCents)->toBe(900)
            ->and($lines[0]->totalCents)->toBe(1800);
    });
})->group('fast');
