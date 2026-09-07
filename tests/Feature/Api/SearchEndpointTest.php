<?php

declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Support\Tenancy;

use function Pest\Laravel\getJson;

use Tests\Support\Api\SearchScenario;

/*
|--------------------------------------------------------------------------
| GET /api/v1/search — #105, the question `GET /availability` cannot answer
|--------------------------------------------------------------------------
|
| "What can I do on Saturday, for four people, leaving from Piraeus?"
|
| The load-bearing assertion in this file is the **party price**. A grid showing
| "από 65 €" that becomes 162,50 € at checkout is the search experience every
| competitor has, and the reason guests telephone instead. So the test asserts
| the number for the party asked about, not that a price is present.
|
*/

it('answers with the party price and the next departure', function (): void {
    [$tenant, $key] = SearchScenario::operator();
    SearchScenario::trip($tenant, 'sunset', adultPriceCents: 4500, localTime: '18:30');

    $response = getJson(SearchScenario::url(['pax' => 4]), ['Authorization' => "Bearer {$key}"]);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.slug', 'sunset')
        ->assertJsonPath('data.0.availability', 'available')
        // Four adults at €45, not the €45 from-price. This is the feature.
        ->assertJsonPath('data.0.party_price_cents', 18000)
        ->assertJsonPath('data.0.next_departure.local_date', SearchScenario::date())
        ->assertJsonPath('data.0.next_departure.local_time', '18:30')
        ->assertJsonPath('data.0.next_departure.seats_available', 12)
        ->assertJsonPath('meta.pax', 4)
        ->assertJsonPath('meta.date', SearchScenario::date())
        ->assertJsonPath('meta.currency', 'EUR');

    // The formatted string is a convenience and the cents are the truth, but a
    // client with no money formatter renders this — so it has to be the same
    // number, in the request's locale.
    expect((string) $response->json('data.0.party_price_formatted'))->toContain('180');
})->group('fast');

it('excludes a boat that cannot seat the party', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    SearchScenario::trip($tenant, 'small-boat', capacity: 4);
    SearchScenario::trip($tenant, 'big-boat', capacity: 20);

    $response = getJson(SearchScenario::url(['pax' => 6]), ['Authorization' => "Bearer {$key}"]);

    // Six people do not fit on a boat that seats four. Showing it and refusing
    // at checkout is the failure mode this endpoint exists to remove.
    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.slug', 'big-boat');
})->group('fast');

it('excludes a departure whose remaining seats are fewer than the party', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    // Twelve seats, ten sold: two left, and a party of four cannot have them.
    SearchScenario::trip($tenant, 'nearly-full', capacity: 12, seatsSold: 10);
    SearchScenario::trip($tenant, 'empty', capacity: 12);

    getJson(SearchScenario::url(['pax' => 4]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.slug', 'empty');
})->group('fast');

it('returns an empty list rather than an error when nothing sails', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    // A trip with no departure on the searched date: it exists, it is
    // published, and it is not an answer to this question.
    SearchScenario::trip($tenant, 'not-sailing', withDeparture: false);

    getJson(SearchScenario::url(['pax' => 2]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.date', SearchScenario::date());
})->group('fast');

it('lists a quote product with no price at all, and last', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    SearchScenario::trip($tenant, 'shared-cruise', adultPriceCents: 4500);

    Tenancy::forTenant($tenant, static fn (): Product => Product::factory()->quote()->create([
        'slug' => 'bespoke-charter',
        'max_pax' => 20,
        // A stale price from a mode change. BKG-24 says a quote product never
        // shows one, so the column must not leak it here either.
        'price_from_cents' => 90000,
    ]));

    $response = getJson(SearchScenario::url(['pax' => 4]), ['Authorization' => "Bearer {$key}"]);

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        // Priced first, "ask us" last: a priceless card at the top is not an
        // answer to "how much", but it stays on the page because for half these
        // operators the charter is the business.
        ->assertJsonPath('data.0.product.slug', 'shared-cruise')
        ->assertJsonPath('data.1.product.slug', 'bespoke-charter')
        ->assertJsonPath('data.1.availability', 'on_request')
        ->assertJsonPath('data.1.party_price_cents', null)
        ->assertJsonPath('data.1.party_price_formatted', null)
        ->assertJsonPath('data.1.next_departure', null);

    expect(json_encode($response->json()))->not->toContain('90000');
})->group('fast');

it('orders by what this party pays, cheapest first', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    SearchScenario::trip($tenant, 'dearer', adultPriceCents: 9000);
    SearchScenario::trip($tenant, 'cheaper', adultPriceCents: 3000);

    getJson(SearchScenario::url(['pax' => 3]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('data.0.product.slug', 'cheaper')
        ->assertJsonPath('data.0.party_price_cents', 9000)
        ->assertJsonPath('data.1.product.slug', 'dearer')
        ->assertJsonPath('data.1.party_price_cents', 27000);
})->group('fast');

it('never answers with another operator catalogue', function (): void {
    [$mine, $key] = SearchScenario::operator();
    [$theirs] = SearchScenario::operator();

    SearchScenario::trip($mine, 'mine');
    SearchScenario::trip($theirs, 'theirs');

    getJson(SearchScenario::url(['pax' => 2]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.slug', 'mine');
})->group('fast');

it('refuses a request with no date, and accepts one with no party', function (): void {
    [$tenant, $key] = SearchScenario::operator();
    SearchScenario::trip($tenant, 'anything');

    // No date is not a search — there is no question to answer.
    getJson('/api/v1/search', ['Authorization' => "Bearer {$key}"])->assertStatus(422);

    // No party is a search by somebody travelling alone, which is a real guest
    // and the honest default.
    getJson(SearchScenario::url(), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonPath('meta.pax', 1)
        ->assertJsonCount(1, 'data');
})->group('fast');

it('matches nothing rather than erroring on an unknown category', function (): void {
    [$tenant, $key] = SearchScenario::operator();
    SearchScenario::trip($tenant, 'sunset-trip', category: ProductCategory::Sunset);

    // WGT-6's rule, applied here: a category that does not exist is dropped and
    // the rest of the question is answered. Widening to the whole catalogue
    // would be the one response that is certainly wrong.
    getJson(SearchScenario::url(['type' => 'not_a_category']), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonCount(1, 'data');

    getJson(SearchScenario::url(['type' => ProductCategory::Sunset->value]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.slug', 'sunset-trip');

    getJson(SearchScenario::url(['type' => ProductCategory::PrivateFullDay->value]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonCount(0, 'data');
})->group('fast');
