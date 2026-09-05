<?php

declare(strict_types=1);

use App\Enums\ApiScope;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| Refusals on the engine endpoints, in both languages — spec I18N-5, §4.1
|--------------------------------------------------------------------------
|
| §4.1: **both languages in every error, always.** The guest-facing clients are a
| Shadow-DOM widget, a Blade page and a WordPress theme, each with its own locale
| resolution — and a booking can fail at the exact moment the widget is
| mid-locale-switch. Shipping both strings costs a few dozen bytes and removes an
| entire class of "the error came back in the wrong language" bug.
|
| So these tests assert the pair, not the active locale. An endpoint that
| returned only the negotiated language would pass a naive test and fail the
| promise.
|
*/

/**
 * A tenant whose default is Greek, holding one bookable sailing.
 *
 * @return array{0: string, 1: Product, 2: Departure, 3: AgeBand}
 */
function localeQuoteFixture(): array
{
    $tenant = Tenant::factory()->create(['default_locale' => 'el']);

    [, $key] = CatalogRequest::key($tenant, scopes: [ApiScope::AvailabilityRead]);

    [$product, $departure, $band] = Tenancy::forTenant($tenant, function (): array {
        $vessel = Vessel::factory()->create(['capacity_max' => 40]);

        $product = Product::factory()->create(['vessel_id' => $vessel->getKey(), 'max_pax' => 12]);

        $adult = AgeBand::factory()->create(['product_id' => $product->getKey()]);
        AgeBand::factory()->create([
            'product_id' => $product->getKey(),
            'code' => 'infant',
            'label' => ['el' => 'Βρέφος', 'en' => 'Infant'],
            'min_age' => 0,
            'max_age' => 2,
            'counts_toward_capacity' => false,
            'is_base' => false,
            'sort_order' => 20,
        ]);

        $plan = RatePlan::factory()->create(['product_id' => $product->getKey(), 'min_lead_time_hours' => 0]);

        // Without a price row there are no pax lines at all, and an assertion
        // on `lines.0.label` would read `null` and look like a locale bug.
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
            ]);

        return [$product->refresh(), $departure, $adult];
    });

    return [$key, $product, $departure, $band];
}

it('carries both languages on an engine refusal', function (): void {
    [$key, $product, $departure] = localeQuoteFixture();

    $infant = Tenancy::forTenant(
        $product->tenant,
        fn (): AgeBand => AgeBand::query()->where('code', 'infant')->firstOrFail(),
    );

    $response = postJson(CatalogRequest::url('/price-quote'), [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $infant->uuid, 'qty' => 2]],
    ], ['Authorization' => "Bearer {$key}"]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'no_counted_pax');

    $error = $response->json('error');

    // The pair, and actually two different strings — an endpoint that filled
    // both slots from the active locale would pass a "not empty" assertion.
    expect($error['message'])->not->toBeEmpty()
        ->and($error['message_el'])->not->toBeEmpty()
        ->and($error['message_el'])->not->toBe($error['message']);

    // `AvailabilityRejection` owns both strings, so this endpoint and
    // `GET /availability` cannot drift into describing one refusal two ways.
    expect($error['message_el'])->toContain('θέση');
})->group('fast');

it('carries both languages on a validation refusal, per field', function (): void {
    [$key, $product, $departure, $band] = localeQuoteFixture();

    $response = postJson(CatalogRequest::url('/price-quote'), [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $band->uuid, 'qty' => 2]],
        'total_cents' => 1,
    ], ['Authorization' => "Bearer {$key}"]);

    $field = $response->assertStatus(422)->json('error.details.fields.total_cents');

    // §4.2. The per-field pair is what a form renders beside the input; the
    // top-level pair is what a client logs.
    expect($field['message'])->not->toBeEmpty()
        ->and($field['message_el'])->not->toBeEmpty()
        ->and($field['message_el'])->not->toBe($field['message']);
})->group('fast');

it('honours an explicit locale on the availability endpoint', function (): void {
    [$key, $product] = localeQuoteFixture();

    $url = CatalogRequest::url('/availability', [
        'product' => $product->uuid,
        'from' => Carbon::now()->addDays(30)->toDateString(),
        'to' => Carbon::now()->addDays(30)->toDateString(),
        'locale' => 'en',
    ]);

    getJson($url, ['Authorization' => "Bearer {$key}", 'Accept-Language' => 'el-GR,el;q=0.9'])
        ->assertOk()
        ->assertHeader('Content-Language', 'en');
})->group('fast');

it('refuses an unsupported locale on both endpoints', function (): void {
    [$key, $product, $departure, $band] = localeQuoteFixture();

    getJson(CatalogRequest::url('/availability', [
        'product' => $product->uuid,
        'from' => Carbon::now()->addDays(30)->toDateString(),
        'to' => Carbon::now()->addDays(30)->toDateString(),
        'locale' => 'fr',
    ]), ['Authorization' => "Bearer {$key}"])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'unsupported_locale');

    // A POST goes through the same middleware, and this asserts it rather than
    // assuming: the locale refusal runs before the controller, so a body that
    // would otherwise be valid must still be turned away.
    postJson(CatalogRequest::url('/price-quote', ['locale' => 'fr']), [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $band->uuid, 'qty' => 2]],
    ], ['Authorization' => "Bearer {$key}"])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'unsupported_locale');
})->group('fast');

it('resolves the price line labels in the negotiated locale', function (): void {
    [$key, $product, $departure, $band] = localeQuoteFixture();

    $greek = postJson(CatalogRequest::url('/price-quote', ['locale' => 'el']), [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $band->uuid, 'qty' => 2]],
    ], ['Authorization' => "Bearer {$key}"]);

    $english = postJson(CatalogRequest::url('/price-quote', ['locale' => 'en']), [
        'product_uuid' => $product->uuid,
        'departure_uuid' => $departure->uuid,
        'pax' => [['age_band_uuid' => $band->uuid, 'qty' => 2]],
    ], ['Authorization' => "Bearer {$key}"]);

    // §3.1: the raw `{"el":…,"en":…}` shape never crosses the API boundary, and
    // a `PriceLineData` label never went through a model accessor — so this is
    // the one place that fallback could have been forgotten.
    expect($greek->json('data.lines.0.label'))->toBe('Ενήλικας')
        ->and($english->json('data.lines.0.label'))->toBe('Adult');
})->group('fast');
