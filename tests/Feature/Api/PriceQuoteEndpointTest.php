<?php

declare(strict_types=1);

use App\Domain\Pricing\Actions\ComputePrice;
use App\Enums\AgeBandPricing;
use App\Enums\ApiScope;
use App\Enums\DepartureStatus;
use App\Enums\ExtraPricing;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Extra;
use App\Models\Product;
use App\Models\ProductExtra;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\VatRate;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| POST /api/v1/price-quote — spec PRC-1, PRC-5, PRC-10, PRC-15, PRC-16, WGT-13
|--------------------------------------------------------------------------
|
| The only way a client learns a price, and a POST that changes nothing.
|
| The assertion this file exists for is **PRC-1**: the client cannot influence
| the number. It is checked twice, because the two halves fail differently — a
| money-shaped field is refused outright, and a legitimate request is compared
| against `ComputePrice`'s own answer rather than against a figure typed into
| the test, which would only prove the endpoint is consistent with itself.
|
*/

/**
 * A per-seat product priced at 65,00 € an adult, with one sailing.
 *
 * @return array{0: string, 1: Product, 2: Departure, 3: AgeBand}
 */
function quoteFixture(): array
{
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::AvailabilityRead]);

    [$product, $departure, $adult] = Tenancy::forTenant($tenant, function (): array {
        $vessel = Vessel::factory()->create(['capacity_max' => 40]);
        $vat = VatRate::factory()->create(['rate_bp' => 1300, 'vat_category' => '2']);

        $product = Product::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'max_pax' => 12,
            'vat_rate_id' => $vat->getKey(),
        ]);

        $adult = AgeBand::factory()->create(['product_id' => $product->getKey()]);
        AgeBand::factory()->create([
            'product_id' => $product->getKey(),
            'code' => 'infant',
            'label' => ['el' => 'Βρέφος', 'en' => 'Infant'],
            'min_age' => 0,
            'max_age' => 2,
            'counts_toward_capacity' => false,
            'pricing_mode' => AgeBandPricing::Multiplier,
            'price_multiplier_bp' => 0,
            'is_base' => false,
            'sort_order' => 20,
        ]);

        $plan = RatePlan::factory()->create([
            'product_id' => $product->getKey(),
            'min_lead_time_hours' => 0,
        ]);

        RatePlanPrice::factory()->create([
            'rate_plan_id' => $plan->getKey(),
            'age_band_id' => $adult->getKey(),
            'price_cents' => 6500,
        ]);

        $date = Carbon::now()->addDays(30);

        $departure = Departure::factory()
            ->at($date->toDateString(), '09:00')
            ->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $vessel->getKey(),
                'capacity' => 12,
                'seats_sold' => 0,
                'status' => DepartureStatus::Scheduled,
            ]);

        return [$product->refresh(), $departure, $adult];
    });

    return [$key, $product, $departure, $adult];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<JsonResponse>
 */
function postQuote(string $key, array $body): TestResponse
{
    return postJson(CatalogRequest::url('/price-quote'), $body, [
        'Authorization' => "Bearer {$key}",
    ]);
}

it('computes the price server-side and returns the full derivation', function (): void {
    [$key, $product, $departure, $adult] = quoteFixture();

    $response = postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 2]],
    ]);

    // Compared against the engine's own answer, not against 13000 typed here.
    // A hardcoded figure would prove only that the endpoint agrees with the
    // person who wrote the test.
    $expected = Tenancy::forTenant($product->tenant, fn () => app(ComputePrice::class)(
        $product,
        $departure->local_date,
        ['adult' => 2],
    ));

    $response->assertOk()
        ->assertJsonPath('data.product_uuid', $product->uuid)
        ->assertJsonPath('data.mode', 'per_seat')
        ->assertJsonPath('data.departure_uuid', $departure->uuid)
        ->assertJsonPath('data.total_cents', $expected->totalCents)
        ->assertJsonPath('data.pax_capacity_total', 2)
        ->assertJsonPath('data.rounding', 'HALF_UP');

    // §3.4's invariant, asserted rather than assumed.
    $data = $response->json('data');

    expect($data['subtotal_cents'] + $data['extras_cents'] - $data['discount_cents'])
        ->toBe($data['total_cents']);

    // "Sufficient to explain the total to a guest a year later."
    expect($data['lines'])->toHaveCount(1)
        ->and($data['lines'][0]['kind'])->toBe('pax')
        ->and($data['lines'][0]['ref'])->toBe('adult')
        ->and($data['lines'][0]['qty'])->toBe(2);
})->group('fast');

it('ignores nothing and refuses everything money-shaped the client sends', function (): void {
    [$key, $product, $departure, $adult] = quoteFixture();

    $response = postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 2]],
        // PRC-1. Refused rather than dropped: an integrator who ships a
        // checkout that appears to apply its own discount should find out on
        // the first request, not when a guest is charged the difference.
        'total_cents' => 1,
        'discount_cents' => 99999,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    expect(array_keys((array) $response->json('error.details.fields')))
        ->toContain('total_cents')
        ->toContain('discount_cents');
})->group('fast');

it('returns the server amount when the hostile field is removed', function (): void {
    [$key, $product, $departure, $adult] = quoteFixture();

    // The other half of PRC-1, and the reason the refusal above is safe: with
    // nothing money-shaped in the body the answer is the server's, and it is
    // 130,00 € for two adults at 65,00 € whatever the client would have liked.
    postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 2]],
    ])->assertJsonPath('data.total_cents', 13000);
})->group('fast');

it('finds a money-shaped field nested inside the window', function (): void {
    [$key, $product, $departure, $adult] = quoteFixture();

    postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 2]],
        // The same assumption wearing a hat.
        'window' => ['local_date' => '2026-07-20', 'price_cents' => 1],
    ])->assertStatus(422);
})->group('fast');

it('carries an expiry equal to the hold TTL', function (): void {
    [$key, $product, $departure, $adult] = quoteFixture();

    $response = postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 2]],
    ]);

    // PRC-15: a quote is a calculation with a shelf life, and the shelf life is
    // the window a guest has to finish checking out before the seats go back.
    $expires = Carbon::parse((string) $response->json('data.expires_at'));
    $ttl = (int) config('kaiki.pricing.quote_ttl_minutes', 20);

    // From now forwards, not the other way: Carbon 3 returns a *signed*
    // difference, so the obvious spelling is negative and passes nothing.
    $minutes = Carbon::now()->diffInMinutes($expires);

    expect($minutes)->toBeGreaterThan($ttl - 1)
        ->and($minutes)->toBeLessThanOrEqual($ttl);

    // `price_token` is M2's, with the endpoint that verifies it.
    expect($response->json('data'))->not->toHaveKey('price_token');
})->group('fast');

it('exposes the VAT breakdown without the rate row id', function (): void {
    [$key, $product, $departure, $adult] = quoteFixture();

    $vat = postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 2]],
    ])->json('data.vat');

    expect($vat['rate_bp'])->toBe(1300)
        // ADR-0002's AADE classification, added to the contract in #37.
        ->and($vat['vat_category'])->toBe('2')
        ->and($vat['included'])->toBeTrue()
        // Never separately rounded halves: they must sum to the total.
        ->and($vat['net_cents'] + $vat['vat_cents'])->toBe(13000)
        // CNV-8: the snapshot carries it, the wire never does.
        ->and($vat)->not->toHaveKey('vat_rate_id');
})->group('fast');

it('refuses a party of infants alone with its own code', function (): void {
    [$key, $product, $departure] = quoteFixture();

    $infant = Tenancy::forTenant(
        $product->tenant,
        fn (): AgeBand => AgeBand::query()->where('code', 'infant')->firstOrFail(),
    );

    // AVL-26. Its own code because the remedy is completely different: add an
    // adult, not pick another date.
    postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $infant->uuid, 'qty' => 2]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'no_counted_pax');
})->group('fast');

it('refuses a party larger than the seats left', function (): void {
    [$key, $product, $departure, $adult] = quoteFixture();

    Tenancy::forTenant($product->tenant, function () use ($departure): void {
        $departure->forceFill(['seats_sold' => 11])->saveQuietly();
    });

    postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 4]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'not_enough_seats');
})->group('fast');

it('refuses a quantity above an extra max_qty', function (): void {
    [$key, $product, $departure, $adult] = quoteFixture();

    $extra = Tenancy::forTenant($product->tenant, function () use ($product): Extra {
        $extra = Extra::factory()->create([
            'pricing_type' => ExtraPricing::PerBooking,
            'price_cents' => 1000,
            'max_qty' => 2,
        ]);

        ProductExtra::factory()->create([
            'product_id' => $product->getKey(),
            'extra_id' => $extra->getKey(),
        ]);

        return $extra;
    });

    // PRC-10, and server-side is the only authority: a client-side maximum is a
    // suggestion, and the widget is on somebody else's page.
    postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 2]],
        'extras' => [['extra_uuid' => $extra->uuid, 'qty' => 9]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
})->group('fast');

it('refuses a voucher code until M2 rather than silently ignoring it', function (): void {
    [$key, $product, $departure, $adult] = quoteFixture();

    // The contract: "An invalid code is an error, not a silent no-op." A code
    // nothing can redeem is invalid, and a guest who typed one would otherwise
    // be charged full price with no explanation.
    postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 2]],
        'voucher_code' => 'KAI-VOUCH-4F7K',
    ])->assertStatus(422);
})->group('fast');

it('refuses to price a quote product', function (): void {
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::AvailabilityRead]);

    [$product, $band] = Tenancy::forTenant($tenant, function (): array {
        $product = Product::factory()->quote()->create();

        return [$product, AgeBand::factory()->create(['product_id' => $product->getKey()])];
    });

    // BKG-24, and the contract's `PriceQuote.mode` enum has no `quote` case.
    postQuote($key, [
        'product_uuid' => $product->uuid,
        'pax' => [['age_band_uuid' => $band->uuid, 'qty' => 2]],
    ])->assertStatus(422);
})->group('fast');

it('will not price another product departure', function (): void {
    [$key, $product, , $adult] = quoteFixture();
    [, $other] = quoteFixture();

    // Scoped by product as well as by tenant: the same operator's other trip
    // would otherwise price the wrong thing at the right operator, which no
    // tenant scope catches.
    postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $other->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 2]],
    ])->assertStatus(422);
})->group('fast');

it('is never cached', function (): void {
    [$key, $product, $departure, $adult] = quoteFixture();

    // §3.6 puts everything guest-specific in `no-store`. A shared cache holding
    // one family's total against a URL another family will request is the worst
    // possible caching bug.
    postQuote($key, [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $adult->uuid, 'qty' => 2]],
    ])->assertHeader('Cache-Control', 'no-store, private');
})->group('fast');
