<?php

declare(strict_types=1);

use App\Enums\ApiScope;
use App\Enums\DepartureStatus;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\getJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| GET /api/v1/availability — spec AVL-29, AVL-30, WGT-16, SEC-2, ADR-0005/6
|--------------------------------------------------------------------------
|
| The engine behind this endpoint is #30 and #31 and is tested exhaustively in
| tests/Feature/Availability. What is tested *here* is the boundary: the shape
| the contract fixes, the day statuses WGT-16 needs, and the two rules that only
| exist at the HTTP layer — the 62-day refusal and the 404 for another
| operator's product.
|
| The load-bearing assertion is that **every requested date is present**. A
| calendar that omits unavailable days cannot grey them out, so it renders them
| as unknown — and a guest reading a blank September concludes the operator has
| stopped running rather than that the boat is booked.
|
*/

/**
 * A per-seat product with one sailing on a known date.
 *
 * @return array{0: string, 1: Product, 2: Departure}
 */
function availabilityFixture(int $capacity = 12, int $seatsSold = 0, int $minPax = 0): array
{
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::AvailabilityRead]);

    [$product, $departure] = Tenancy::forTenant($tenant, function () use ($capacity, $seatsSold, $minPax): array {
        $vessel = Vessel::factory()->create(['capacity_max' => 40]);

        $product = Product::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'max_pax' => $capacity,
            'min_pax' => $minPax,
        ]);

        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        RatePlan::factory()->create([
            'product_id' => $product->getKey(),
            'min_lead_time_hours' => 0,
            'max_advance_days' => 365,
        ]);

        // `at()` writes `local_date`, `local_time` and both UTC instants
        // through `LocalDateTimeResolver`, which is the only way CNV-3's
        // three-column agreement survives a date near a clock change.
        $departure = Departure::factory()
            ->at(availabilityDate(), '09:00')
            ->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $vessel->getKey(),
                'capacity' => $capacity,
                'min_pax' => $minPax,
                'seats_sold' => $seatsSold,
                'status' => DepartureStatus::Scheduled,
            ]);

        return [$product->refresh(), $departure];
    });

    return [$key, $product, $departure];
}

/** A fixed date far enough ahead that no lead-time rule bites. */
function availabilityDate(): string
{
    return Carbon::now()->addDays(30)->toDateString();
}

/** @param array<string, mixed> $extra */
function availabilityUrl(Product $product, array $extra = []): string
{
    return CatalogRequest::url('/availability', array_merge([
        'product' => $product->uuid,
        'from' => availabilityDate(),
        'to' => availabilityDate(),
    ], $extra));
}

it('returns the contract shape for a bookable per-seat departure', function (): void {
    [$key, $product, $departure] = availabilityFixture();

    $response = getJson(availabilityUrl($product), ['Authorization' => "Bearer {$key}"]);

    $response->assertOk()
        ->assertJsonPath('data.0.local_date', availabilityDate())
        ->assertJsonPath('data.0.status', 'available')
        ->assertJsonPath('data.0.departures.0.uuid', $departure->uuid)
        ->assertJsonPath('data.0.departures.0.status', 'scheduled')
        ->assertJsonPath('data.0.departures.0.capacity', 12)
        ->assertJsonPath('data.0.departures.0.seats_available', 12)
        ->assertJsonPath('data.0.departures.0.is_guaranteed', false)
        ->assertJsonPath('data.0.windows', [])
        ->assertJsonPath('meta.mode', 'per_seat')
        ->assertJsonPath('meta.timezone', 'Europe/Athens');

    // `LocalWindow` carries both halves so a client never converts a timezone.
    $window = $response->json('data.0.departures.0.window');

    expect($window['local_time'])->toBe('09:00')
        ->and($window['starts_at'])->toEndWith('Z')
        ->and($window['timezone'])->toBe('Europe/Athens')
        ->and($window['dst_ambiguous'])->toBeFalse();

    // Departure start minus the product's 30-minute check-in offset, local.
    expect($response->json('data.0.departures.0.check_in_local_time'))->toBe('08:30');
})->group('fast');

it('refuses a range longer than 62 days rather than truncating it', function (): void {
    [$key, $product] = availabilityFixture();

    $response = getJson(
        availabilityUrl($product, ['to' => Carbon::parse(availabilityDate())->addDays(90)->toDateString()]),
        ['Authorization' => "Bearer {$key}"],
    );

    // AVL-29. A trimmed range renders as empty days and the guest concludes the
    // boat does not sail in September.
    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_date_range')
        ->assertJsonPath('error.details.max_days', 62);
})->group('fast');

it('returns every requested date, including the ones with nothing on them', function (): void {
    [$key, $product] = availabilityFixture();

    $response = getJson(
        availabilityUrl($product, ['to' => Carbon::parse(availabilityDate())->addDays(4)->toDateString()]),
        ['Authorization' => "Bearer {$key}"],
    );

    $response->assertOk()->assertJsonCount(5, 'data');

    $statuses = array_column($response->json('data'), 'status');

    // WGT-16: the four days with no sailing say so, rather than being absent.
    expect($statuses[0])->toBe('available')
        ->and(array_slice($statuses, 1))->each->toBe('not_operating');
})->group('fast');

it('says sold_out rather than unavailable when the party will not fit', function (): void {
    [$key, $product] = availabilityFixture(capacity: 4, seatsSold: 3);

    $response = getJson(availabilityUrl($product, ['pax' => 3]), ['Authorization' => "Bearer {$key}"]);

    // The distinction WGT-16 is about: "sold out" sends a guest to another
    // date, "unavailable" sends them nowhere, and both would be true.
    $response->assertOk()
        ->assertJsonPath('data.0.status', 'sold_out')
        // The refused departure is not published — only bookable ones appear,
        // and its reason lives in the day's status.
        ->assertJsonPath('data.0.departures', []);
})->group('fast');

it('reports how many more passengers would guarantee a departure', function (): void {
    [$key, $product] = availabilityFixture(capacity: 20, seatsSold: 2, minPax: 6);

    getJson(availabilityUrl($product), ['Authorization' => "Bearer {$key}"])
        ->assertJsonPath('data.0.departures.0.is_guaranteed', false)
        ->assertJsonPath('data.0.departures.0.seats_to_guarantee', 4);
})->group('fast');

it('hides a cancelled departure entirely', function (): void {
    [$key, $product, $departure] = availabilityFixture();

    Tenancy::forTenant($product->tenant, function () use ($departure): void {
        $departure->forceFill([
            'status' => DepartureStatus::Cancelled,
            'cancelled_at' => Carbon::now(),
        ])->saveQuietly();
    });

    // AVL-28 removes it rather than reporting it, so the day reads as one the
    // boat does not sail.
    getJson(availabilityUrl($product), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'not_operating')
        ->assertJsonPath('data.0.departures', []);
})->group('fast');

it('answers a per-vessel product with windows rather than departures', function (): void {
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::AvailabilityRead]);

    $product = Tenancy::forTenant($tenant, function (): Product {
        $vessel = Vessel::factory()->create(['capacity_max' => 12]);

        $product = Product::factory()->perVessel()->create([
            'vessel_id' => $vessel->getKey(),
            'default_start_time' => '10:00',
        ]);

        RatePlan::factory()->create([
            'product_id' => $product->getKey(),
            'vessel_price_cents' => 95000,
            'min_lead_time_hours' => 0,
        ]);

        return $product;
    });

    $response = getJson(availabilityUrl($product), ['Authorization' => "Bearer {$key}"]);

    $response->assertOk()
        ->assertJsonPath('meta.mode', 'per_vessel')
        ->assertJsonPath('data.0.departures', [])
        ->assertJsonPath('data.0.windows.0.is_available', true)
        ->assertJsonPath('data.0.windows.0.flexible_start', false)
        ->assertJsonPath('data.0.windows.0.unavailable_reason', null);
})->group('fast');

it('answers a quote product with on_request and never a price', function (): void {
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::AvailabilityRead]);

    $product = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->quote()->create([
        'price_from_cents' => 9900,
    ]));

    // BKG-24 and WGT-13: an operator quotes these by hand, so a number here
    // would be one they never agreed to.
    getJson(availabilityUrl($product), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('data.0.status', 'on_request')
        ->assertJsonPath('data.0.from_price_cents', null)
        ->assertJsonPath('data.0.departures', [])
        ->assertJsonPath('data.0.windows', []);
})->group('fast');

it('answers another operator product with 404', function (): void {
    [, $key] = CatalogRequest::key(scopes: [ApiScope::AvailabilityRead]);
    [$other] = CatalogRequest::key();

    $foreign = Tenancy::forTenant($other, fn (): Product => Product::factory()->create());

    getJson(availabilityUrl($foreign), ['Authorization' => "Bearer {$key}"])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
})->group('fast');

it('is cacheable for the window the widget assumes', function (): void {
    [$key, $product] = availabilityFixture();

    // §3.6, and half the catalogue's sixty: availability is the one payload
    // that goes stale because somebody else bought a seat. WGT-17's in-memory
    // widget cache sits inside this window.
    getJson(availabilityUrl($product), ['Authorization' => "Bearer {$key}"])
        ->assertHeader('Cache-Control', 'max-age=30, public');
})->group('fast');

it('refuses a key without the availability.read scope', function (): void {
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::ProductsRead]);

    $product = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create());

    // Separate from `products.read` on purpose: a key issued for an SEO sync
    // has no business asking who has seats left on Tuesday.
    getJson(availabilityUrl($product), ['Authorization' => "Bearer {$key}"])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope');
})->group('fast');
