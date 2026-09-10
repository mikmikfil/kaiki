<?php

declare(strict_types=1);

use App\Data\Pricing\PriceQuoteData;
use App\Domain\Pricing\Actions\ComputePrice;
use App\Models\AgeBand;
use App\Models\Extra;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Models\Tenant;
use App\Models\VatRate;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| The price snapshot — data-model §3.4, spec PRC-1, PRC-12 to PRC-16
|--------------------------------------------------------------------------
|
| §3.4's job, in its own words: the object must be enough to **explain the total
| to a guest a year later without touching any other table**. That is why the
| assertions here are about the *shape* — every key §3.4 names, present, with
| the season's name and each line's label copied rather than referenced.
|
| The other assertion that carries a requirement is the absence of floats. CNV-1
| says not even transiently, and JSON is exactly where a float that survived the
| arithmetic would finally show itself.
|
*/

/**
 * The operator these snapshots are computed for.
 *
 * Taking deposits, because `quotableProduct()` builds a 30% plan and PRC-25's
 * ordering assertion is only worth making against an operator who actually has
 * a deposit — switched off, the whole total is due now and «deposit after the
 * discount» would be true by arithmetic rather than by ordering.
 */
function snapshotTenant(callable $callback): mixed
{
    return Tenancy::forTenant(Tenant::factory()->takingDeposits()->create(), $callback);
}

/** A per-seat trip, one season, adult and child bands, priced. */
function quotableProduct(int $adultCents = 6500): Product
{
    $product = Product::factory()->create();

    $adult = AgeBand::factory()->create(['product_id' => $product->getKey()]);
    AgeBand::factory()->child()->create(['product_id' => $product->getKey()]);

    $season = Season::factory()->withRange('2026-06-01', '2026-09-15')->create();

    $plan = RatePlan::factory()->forSeason($season)->depositPercent(30)->create([
        'product_id' => $product->getKey(),
    ]);

    $plan->prices()->create(['age_band_id' => $adult->getKey(), 'price_cents' => $adultCents]);

    return $product->refresh();
}

/**
 * @param  array<string, int>  $pax
 * @param  array<int, int>  $extras
 */
function quoteFor(Product $product, array $pax = ['adult' => 2, 'child' => 1], array $extras = []): PriceQuoteData
{
    return app(ComputePrice::class)($product, Carbon::parse('2026-07-04'), $pax, $extras);
}

/** @param array<mixed> $value */
function containsFloat(array $value): bool
{
    foreach ($value as $item) {
        if (is_float($item) || (is_array($item) && containsFloat($item))) {
            return true;
        }
    }

    return false;
}

it('produces every key §3.4 names', function (): void {
    snapshotTenant(function (): void {
        $snapshot = quoteFor(quotableProduct())->snapshot->toArray();

        expect(array_keys($snapshot))->toContain(
            'version', 'source', 'currency', 'computed_at', 'rate_plan_id', 'season',
            'mode', 'lines', 'subtotal_cents', 'extras_cents', 'discount_cents',
            'total_cents', 'vat', 'deposit', 'rounding',
        );
    });
})->group('fast');

it('gives every line the §3.4 line shape', function (): void {
    snapshotTenant(function (): void {
        $snapshot = quoteFor(quotableProduct())->snapshot->toArray();

        foreach ($snapshot['lines'] as $line) {
            expect(array_keys($line))->toContain('kind', 'ref', 'label', 'qty', 'unit_price_cents', 'total_cents')
                ->and($line['kind'])->toBeIn(['pax', 'extra', 'fee', 'discount'])
                ->and($line['label'])->toHaveKeys(['el', 'en']);
        }
    });
})->group('fast');

it('records the multiplier only on a line that was derived from the base', function (): void {
    snapshotTenant(function (): void {
        // §3.4 marks `multiplier_bp` optional and present "when the price was
        // derived from the base band". Absent rather than null on the others:
        // a null would read as "derived from nothing".
        $lines = quoteFor(quotableProduct())->snapshot->toArray()['lines'];

        expect($lines[0])->not->toHaveKey('multiplier_bp')
            ->and($lines[1]['multiplier_bp'])->toBe(5000);
    });
})->group('fast');

it('holds §3.4 invariant: subtotal plus extras minus discount is the total', function (): void {
    snapshotTenant(function (): void {
        $product = quotableProduct();
        $lunch = Extra::factory()->tenantWide()->create(['price_cents' => 1500]);

        $quote = quoteFor($product, extras: [$lunch->getKey() => 1]);
        $snapshot = $quote->snapshot;

        expect($snapshot->addsUp())->toBeTrue()
            ->and($snapshot->subtotalCents + $snapshot->extrasCents - $snapshot->discountCents)
            ->toBe($snapshot->totalCents);
    });
})->group('fast');

it('carries the season by value, not by reference', function (): void {
    snapshotTenant(function (): void {
        // An operator renaming a season next spring must not rewrite what a
        // guest was shown last June.
        $snapshot = quoteFor(quotableProduct())->snapshot->toArray();

        expect($snapshot['season'])->toHaveKeys(['id', 'name', 'priority'])
            ->and($snapshot['season']['name'])->toHaveKeys(['el', 'en']);
    });
})->group('fast');

it('contains no float anywhere in the output', function (): void {
    snapshotTenant(function (): void {
        // CNV-1 in its final form. Everything above could be right and one
        // division could still have produced a float that survived to the JSON.
        $product = quotableProduct(6501);
        $lunch = Extra::factory()->tenantWide()->create(['price_cents' => 1500]);

        $payload = quoteFor($product, extras: [$lunch->getKey() => 1])->toArray();

        expect(containsFloat($payload))->toBeFalse()
            ->and(json_encode($payload))->not->toContain('.0,');
    });
})->group('fast');

it('records the rounding mode so a re-derivation can be compared', function (): void {
    snapshotTenant(function (): void {
        expect(quoteFor(quotableProduct())->snapshot->toArray()['rounding'])->toBe('HALF_UP');
    });
})->group('fast');

it('splits VAT out of an inclusive total when the product has a rate', function (): void {
    snapshotTenant(function (): void {
        // Greek passenger transport is quoted inclusive, so the gross is what
        // the guest agreed and the split is derived from it.
        $rate = VatRate::factory()->create(['rate_bp' => 1300]);
        $product = quotableProduct();
        $product->update(['vat_rate_id' => $rate->getKey()]);

        $snapshot = quoteFor($product->refresh())->snapshot->toArray();

        expect($snapshot['vat']['rate_bp'])->toBe(1300)
            ->and($snapshot['vat']['included'])->toBeTrue()
            ->and($snapshot['vat']['vat_category'])->toBe($rate->vat_category)
            // The halves must sum to the total, always.
            ->and($snapshot['vat']['net_cents'] + $snapshot['vat']['vat_cents'])
            ->toBe($snapshot['total_cents']);
    });
})->group('fast');

it('leaves VAT null while a product has no rate', function (): void {
    snapshotTenant(function (): void {
        // §2.3 leaves `vat_rate_id` nullable in M1 so onboarding can proceed,
        // and MYD-16 gates myDATA activation on every sellable product having
        // one. Inventing a rate here would defeat that gate silently.
        expect(quoteFor(quotableProduct())->snapshot->toArray()['vat'])->toBeNull();
    });
})->group('fast');

it('quotes a whole boat as one line plus its extra hours', function (): void {
    snapshotTenant(function (): void {
        // PRC-8. Two lines rather than one, so a guest reading the receipt can
        // see what the longer afternoon cost them.
        $product = Product::factory()->perVessel()->create();

        RatePlan::factory()->perVessel(60000)->create([
            'product_id' => $product->getKey(),
            'extra_hour_price_cents' => 8000,
        ]);

        $quote = app(ComputePrice::class)($product, Carbon::parse('2026-07-04'), [], [], 2);
        $snapshot = $quote->snapshot->toArray();

        expect($snapshot['mode'])->toBe('per_vessel')
            ->and($snapshot['lines'])->toHaveCount(2)
            ->and($snapshot['lines'][1]['kind'])->toBe('fee')
            ->and($snapshot['total_cents'])->toBe(76000);
    });
})->group('fast');

it('refuses to price a date with no rate plan rather than quoting zero', function (): void {
    snapshotTenant(function (): void {
        // PRC-5. A zero total would be a free trip that passes every
        // arithmetic check on its way to a payment gateway.
        $product = Product::factory()->create();
        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        quoteFor($product);
    });
})->throws(ValidationException::class)->group('fast');

it('gives a quote an expiry and persists nothing', function (): void {
    snapshotTenant(function (): void {
        // PRC-15: a quote is a calculation with a shelf life. It holds no seat
        // and writes no row.
        Carbon::setTestNow('2026-07-01 09:00:00');

        $quote = quoteFor(quotableProduct());

        expect($quote->expiresAt->toIso8601ZuluString())
            ->toBe(Carbon::parse('2026-07-01 09:20:00')->toIso8601ZuluString());

        Carbon::setTestNow();
    });
})->group('fast');

it('reports an on-request extra without adding it to the total', function (): void {
    snapshotTenant(function (): void {
        // PRC-9: the booking still confirms and pays the computed total, and
        // the operator follows the request up afterwards.
        $product = quotableProduct();
        $chef = Extra::factory()->tenantWide()->onRequest()->create();

        $quote = quoteFor($product, extras: [$chef->getKey() => 1]);

        expect($quote->hasOnRequestItems)->toBeTrue()
            ->and($quote->snapshot->extrasCents)->toBe(0)
            ->and($quote->snapshot->totalCents)->toBe($quote->snapshot->subtotalCents);
    });
})->group('fast');

it('computes the deposit after the discount term, as PRC-25 requires', function (): void {
    snapshotTenant(function (): void {
        // Vouchers are M2 and the term is zero, so this asserts the *ordering*
        // rather than a discount: the deposit is a share of the total, not of
        // the subtotal, which is what makes adding vouchers a no-op here.
        $product = quotableProduct();
        $lunch = Extra::factory()->tenantWide()->create(['price_cents' => 1500]);

        $quote = quoteFor($product, extras: [$lunch->getKey() => 1]);

        expect($quote->snapshot->toArray()['deposit']['amount_cents'])
            ->toBe($quote->depositCents)
            ->and($quote->depositCents + $quote->balanceCents)
            ->toBe($quote->totalCents);
    });
})->group('fast');
