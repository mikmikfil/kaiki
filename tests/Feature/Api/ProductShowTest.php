<?php

declare(strict_types=1);

use App\Enums\ExtraPricing;
use App\Enums\ProductStatus;
use App\Models\AgeBand;
use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use App\Models\Extra;
use App\Models\Port;
use App\Models\Product;
use App\Models\ProductExtra;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| GET /api/v1/products/{uuid} — spec HOS-1, SEC-1, SEC-2, CAT-7, CAT-12, CXL-1
|--------------------------------------------------------------------------
|
| The full product, and the endpoint the hosted page and the WordPress permalink
| resolve with — by slug as often as by uuid.
|
| The load-bearing assertion in this file is the **404 for another operator's
| uuid**. SEC-1 and SEC-2 require it to be indistinguishable from a uuid that
| does not exist: a 403 confirms the product is real and belongs to someone
| else, and that is a leak rather than a courtesy.
|
*/

/**
 * A product with everything hanging off it, for the payload-shape assertions.
 *
 * @return array{0: Tenant, 1: string, 2: Product}
 */
function catalogProduct(): array
{
    [$tenant, $key] = CatalogRequest::key();

    $product = Tenancy::forTenant($tenant, function (): Product {
        $port = Port::factory()->create([
            'name' => ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'],
            'lat' => 37.9339000,
            'lng' => 23.6512000,
        ]);

        $policy = CancellationPolicy::factory()->create();
        CancellationPolicyTier::factory()->create([
            'cancellation_policy_id' => $policy->getKey(),
            'days_before' => 3,
            'refund_percent' => 25,
        ]);
        CancellationPolicyTier::factory()->create([
            'cancellation_policy_id' => $policy->getKey(),
            'days_before' => 14,
            'refund_percent' => 100,
        ]);

        $product = Product::factory()->withItinerary()->create([
            'slug' => 'sunset-cruise-aegina',
            'meeting_point_id' => $port->getKey(),
            'cancellation_policy_id' => $policy->getKey(),
            'includes' => ['el' => ['Γεύμα'], 'en' => ['Lunch']],
            'excludes' => ['el' => [], 'en' => []],
        ]);

        $adult = AgeBand::factory()->create(['product_id' => $product->getKey()]);
        AgeBand::factory()->create([
            'product_id' => $product->getKey(),
            'code' => 'infant',
            'label' => ['el' => 'Βρέφος', 'en' => 'Infant'],
            'min_age' => 0,
            'max_age' => 2,
            'counts_toward_capacity' => false,
            'requires_adult' => true,
            'is_base' => false,
            'sort_order' => 20,
        ]);

        $extra = Extra::factory()->create(['price_cents' => 1500, 'is_required' => true, 'max_qty' => 4]);
        ProductExtra::factory()->create([
            'product_id' => $product->getKey(),
            'extra_id' => $extra->getKey(),
            'price_cents_override' => 1000,
            'is_required_override' => false,
        ]);

        $plan = RatePlan::factory()->create([
            'product_id' => $product->getKey(),
            'min_lead_time_hours' => 12,
            'max_advance_days' => 365,
        ]);
        RatePlan::factory()->create([
            'product_id' => $product->getKey(),
            'min_lead_time_hours' => 48,
            'max_advance_days' => 180,
        ]);
        // Inactive, and far stricter than both. If `booking_window` ever counts
        // it the projection collapses to 999 hours and one day, which is what
        // makes this row worth creating.
        RatePlan::factory()->create([
            'product_id' => $product->getKey(),
            'min_lead_time_hours' => 999,
            'max_advance_days' => 1,
            'is_active' => false,
        ]);

        // `price_from_cents` is derived (#33) and the observers rewrite it on
        // every rate-plan write — setting the column on the factory and then
        // creating a plan leaves it null, which is the derived column working
        // correctly. So the price is created rather than asserted into place.
        RatePlanPrice::factory()->create([
            'rate_plan_id' => $plan->getKey(),
            'age_band_id' => $adult->getKey(),
            'price_cents' => 6500,
        ]);

        return $product->refresh();
    });

    return [$tenant, $key, $product];
}

it('returns the full detail payload', function (): void {
    [, $key, $product] = catalogProduct();

    $response = getJson(
        CatalogRequest::url("/products/{$product->uuid}"),
        ['Authorization' => "Bearer {$key}", 'Accept-Language' => 'en'],
    );

    $response->assertOk()
        ->assertJsonPath('data.uuid', $product->uuid)
        ->assertJsonPath('data.slug', 'sunset-cruise-aegina')
        ->assertJsonPath('data.title', 'Full-day cruise')
        ->assertJsonPath('data.from_price_cents', 6500)
        ->assertJsonPath('data.includes', ['Lunch'])
        // Null hides the section; an empty array is "configured as empty".
        // Two different statements to a guest, so they stay different here.
        ->assertJsonPath('data.excludes', [])
        ->assertJsonPath('data.what_to_bring', null)
        ->assertJsonPath('data.check_in_offset_minutes', 30)
        ->assertJsonPath('data.timezone', 'Europe/Athens')
        ->assertJsonPath('data.meeting_point.name', 'Zea Marina');

    // Null until #104 built the page it addresses. It is the trip's hosted
    // page — the address a WordPress SEO sync (WPP-6) points at so the two
    // never compete in search — and it is on the hosted host, not this one.
    expect($response->json('data.seo.canonical_url'))
        ->toEndWith('/sunset-cruise-aegina')
        ->toContain((string) config('kaiki.tenancy.hosted_host'));

    // `default_start_time` is wall time in `HH:MM`, not the driver's
    // `09:00:00` and not an instant.
    expect($response->json('data.default_start_time'))->toBe('09:00');

    // `lat`/`lng` are JSON numbers here even though the column is `decimal:7`
    // and the model casts it to a string — the cast happens at the boundary,
    // where nothing arithmetic follows it.
    expect($response->json('data.meeting_point.lat'))->toBeFloat();
})->group('fast');

it('joins itinerary labels to the geo sidecar by key', function (): void {
    [, $key, $product] = catalogProduct();

    $response = getJson(
        CatalogRequest::url("/products/{$product->uuid}"),
        ['Authorization' => "Bearer {$key}", 'Accept-Language' => 'en'],
    );

    $stops = $response->json('data.itinerary_stops');

    expect($stops)->toHaveCount(2)
        ->and($stops[1]['key'])->toBe('s2')
        ->and($stops[1]['name'])->toBe('Vlychada Bay')
        ->and($stops[1]['lat'])->toBe(37.6721)
        ->and($stops[1]['lng'])->toBe(23.441);

    // `_geo` is a pseudo-locale that `getTranslations()` hands back alongside
    // `el` and `en`. If it ever leaks into the stop list this fails loudly.
    expect(array_column($stops, 'key'))->not->toContain('_geo');
})->group('fast');

it('returns age bands with the capacity flag that decides a seat', function (): void {
    [, $key, $product] = catalogProduct();

    $response = getJson(
        CatalogRequest::url("/products/{$product->uuid}"),
        ['Authorization' => "Bearer {$key}", 'Accept-Language' => 'en'],
    );

    $bands = $response->json('data.age_bands');

    // Ordered by the operator's own sequence, not by age.
    expect(array_column($bands, 'code'))->toBe(['adult', 'infant']);

    // CAT-8: an infant on a lap is a person aboard and not a seat sold.
    expect($bands[1]['counts_toward_capacity'])->toBeFalse()
        ->and($bands[1]['requires_adult'])->toBeTrue()
        ->and($bands[1]['label'])->toBe('Infant');

    // §9 item 6 is still open, so the advisory per-band price is not emitted.
    expect($bands[0])->not->toHaveKey('from_price_cents');
})->group('fast');

it('applies the product overrides to an extra', function (): void {
    [, $key, $product] = catalogProduct();

    $response = getJson(
        CatalogRequest::url("/products/{$product->uuid}"),
        ['Authorization' => "Bearer {$key}", 'Accept-Language' => 'en'],
    );

    $extra = $response->json('data.extras.0');

    expect($extra['price_cents'])->toBe(1000)
        // The tri-state override, and the direction that matters: a `false`
        // has to beat the extra's `true`, which `?:` would get wrong.
        ->and($extra['is_required'])->toBeFalse()
        // Not overridable, so it comes through from the extra itself.
        ->and($extra['max_qty'])->toBe(4)
        ->and($extra['description'])->toBe('Lunch and a drink on board.');
})->group('fast');

it('never prices an on-request extra', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    $product = Tenancy::forTenant($tenant, function (): Product {
        $product = Product::factory()->create();

        $extra = Extra::factory()->create([
            'pricing_type' => ExtraPricing::OnRequest,
            'price_cents' => null,
        ]);

        ProductExtra::factory()->create([
            'product_id' => $product->getKey(),
            'extra_id' => $extra->getKey(),
            // An override cannot promote an unpriced extra into a total.
            'price_cents_override' => 5000,
        ]);

        return $product;
    });

    getJson(CatalogRequest::url("/products/{$product->uuid}"), ['Authorization' => "Bearer {$key}"])
        ->assertJsonPath('data.extras.0.pricing_type', 'on_request')
        ->assertJsonPath('data.extras.0.price_cents', null);
})->group('fast');

it('summarises the live cancellation policy with no capture date', function (): void {
    [, $key, $product] = catalogProduct();

    $response = getJson(
        CatalogRequest::url("/products/{$product->uuid}"),
        ['Authorization' => "Bearer {$key}", 'Accept-Language' => 'en'],
    );

    $policy = $response->json('data.cancellation_policy');

    expect($policy['name'])->toBe('Flexible')
        ->and($policy['free_cancellation_hours'])->toBe(48)
        // Sorted `days_before` descending — evaluation order (§3.3).
        ->and(array_column($policy['tiers'], 'days_before'))->toBe([14, 3])
        // CXL-1: this is the live catalogue policy, not a booking's frozen
        // snapshot, and the null is the field saying which of the two it is.
        ->and($policy['captured_at'])->toBeNull();
})->group('fast');

it('projects the strictest booking window across active rate plans', function (): void {
    [, $key, $product] = catalogProduct();

    $response = getJson(CatalogRequest::url("/products/{$product->uuid}"), ['Authorization' => "Bearer {$key}"]);

    // Largest lead time and smallest advance window across the *active* plans.
    // The inactive plan would have made both far stricter, which is how this
    // test would catch it being counted.
    $response->assertJsonPath('data.booking_window.min_lead_time_hours', 48)
        ->assertJsonPath('data.booking_window.max_advance_days', 180);
})->group('fast');

it('resolves by slug as well as by uuid', function (): void {
    [, $key, $product] = catalogProduct();

    getJson(CatalogRequest::url('/products/sunset-cruise-aegina'), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('data.uuid', $product->uuid);
})->group('fast');

it('answers another operator uuid with 404, not 403', function (): void {
    [, $key] = CatalogRequest::key();
    [$other] = CatalogRequest::key();

    $foreign = Tenancy::forTenant($other, fn (): Product => Product::factory()->create());

    $response = getJson(
        CatalogRequest::url("/products/{$foreign->uuid}"),
        ['Authorization' => "Bearer {$key}"],
    );

    // SEC-1, SEC-2. Identical to a uuid that was never issued — anything else
    // confirms the product exists somewhere.
    $response->assertStatus(404)->assertJsonPath('error.code', 'not_found');

    $unknown = getJson(
        CatalogRequest::url('/products/' . Str::uuid()->toString()),
        ['Authorization' => "Bearer {$key}"],
    );

    expect($response->json())->toBe($unknown->json());
})->group('fast');

it('hides a product that is not active', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    $hidden = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create([
        'status' => ProductStatus::Inactive,
    ]));

    getJson(CatalogRequest::url("/products/{$hidden->uuid}"), ['Authorization' => "Bearer {$key}"])
        ->assertStatus(404);
})->group('fast');

it('answers a conditional request with 304 and no body', function (): void {
    [, $key, $product] = catalogProduct();

    $first = getJson(CatalogRequest::url("/products/{$product->uuid}"), ['Authorization' => "Bearer {$key}"]);

    $etag = (string) $first->headers->get('ETag');

    expect($etag)->toStartWith('"');

    $second = getJson(CatalogRequest::url("/products/{$product->uuid}"), [
        'Authorization' => "Bearer {$key}",
        // A weak validator, which is what a proxy sends back. Comparing the raw
        // header would miss the `W/` and turn every poll into a full response.
        'If-None-Match' => "W/{$etag}",
    ]);

    $second->assertStatus(304);
    expect($second->getContent())->toBe('');
})->group('fast');
