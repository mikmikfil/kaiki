<?php

declare(strict_types=1);

use App\Domain\Catalog\Support\SearchFilters;

use function Pest\Laravel\getJson;

use Tests\Support\Api\SearchScenario;

/*
|--------------------------------------------------------------------------
| #105: a filter the operator switched off is IGNORED, not hidden
|--------------------------------------------------------------------------
|
| The issue's own note names the failure this file exists to prevent: *"hiding
| it in the template and honouring it in the controller is the version that
| passes a visual review and fails the operator who turned it off precisely
| because their answer would be embarrassing."*
|
| So every assertion here compares the **result set** of a request carrying a
| disabled filter against the unfiltered one. A page that merely stopped drawing
| the control would pass a screenshot review and fail every one of these.
|
*/

it('ignores a disabled filter and answers as though it were absent', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    $piraeus = SearchScenario::port($tenant, 'Zea Marina');
    $aegina = SearchScenario::port($tenant, 'Aegina');

    SearchScenario::trip($tenant, 'from-piraeus', port: $piraeus);
    SearchScenario::trip($tenant, 'from-aegina', port: $aegina);

    // The operator switched the port filter off — three trips, two harbours,
    // and a filter that would show a guest one boat and hide the other.
    SearchScenario::filters($tenant, [...SearchFilters::defaults(), SearchFilters::PORT => false]);

    $filtered = getJson(SearchScenario::url(['port' => $piraeus->uuid]), ['Authorization' => "Bearer {$key}"]);
    $unfiltered = getJson(SearchScenario::url(), ['Authorization' => "Bearer {$key}"]);

    $filtered->assertOk();
    $unfiltered->assertOk();

    // Identical result sets: the crafted query string changed nothing.
    expect($filtered->json('data'))->toBe($unfiltered->json('data'))
        ->and($filtered->json('data'))->toHaveCount(2)
        // And the response says so, rather than leaving the client to wonder.
        ->and($filtered->json('meta.filters_enabled'))->not->toContain(SearchFilters::PORT)
        ->and($filtered->json('meta.applied'))->not->toHaveKey('port');
})->group('fast');

it('honours the same filter once the operator switches it on', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    $piraeus = SearchScenario::port($tenant, 'Zea Marina');
    $aegina = SearchScenario::port($tenant, 'Aegina');

    SearchScenario::trip($tenant, 'from-piraeus', port: $piraeus);
    SearchScenario::trip($tenant, 'from-aegina', port: $aegina);

    // The default has the port filter on, so this is the same request against
    // the same data with one setting changed — which is what makes the pair of
    // tests evidence rather than two separate claims.
    SearchScenario::filters($tenant, SearchFilters::defaults());

    getJson(SearchScenario::url(['port' => $piraeus->uuid]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.slug', 'from-piraeus')
        ->assertJsonPath('meta.applied.port', $piraeus->uuid);
})->group('fast');

it('ignores the three filters that are off by default', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    SearchScenario::trip($tenant, 'long-and-dear', adultPriceCents: 12000, durationMinutes: 600);
    SearchScenario::trip($tenant, 'short-and-cheap', adultPriceCents: 2000, durationMinutes: 120);

    // Duration, price ceiling and vessel are **available but off** — the design
    // review of 2026-09-04 settled that an operator with three trips wants two
    // filters, not seven.
    $unfiltered = getJson(SearchScenario::url(['pax' => 2]), ['Authorization' => "Bearer {$key}"]);

    $crafted = getJson(
        SearchScenario::url(['pax' => 2, 'duration_max' => 130, 'price_max' => 5000]),
        ['Authorization' => "Bearer {$key}"],
    );

    expect($crafted->json('data'))->toBe($unfiltered->json('data'))
        ->and($crafted->json('data'))->toHaveCount(2);
})->group('fast');

it('applies duration and price ceilings once the operator enables them', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    SearchScenario::trip($tenant, 'long-and-dear', adultPriceCents: 12000, durationMinutes: 600);
    SearchScenario::trip($tenant, 'short-and-cheap', adultPriceCents: 2000, durationMinutes: 120);

    SearchScenario::filters($tenant, [
        ...SearchFilters::defaults(),
        SearchFilters::DURATION => true,
        SearchFilters::PRICE => true,
    ]);

    // The ceiling is compared against what **this party** pays — two guests at
    // €20 is €40 — not against a per-person figure. A per-person comparison
    // would show a party of six a trip they cannot afford.
    getJson(
        SearchScenario::url(['pax' => 2, 'duration_max' => 130, 'price_max' => 5000]),
        ['Authorization' => "Bearer {$key}"],
    )
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product.slug', 'short-and-cheap')
        ->assertJsonPath('data.0.party_price_cents', 4000);

    // The same party, one euro under the pair price: nothing matches, and that
    // is the ceiling working rather than the filter being ignored.
    getJson(
        SearchScenario::url(['pax' => 2, 'price_max' => 3900]),
        ['Authorization' => "Bearer {$key}"],
    )
        ->assertOk()
        ->assertJsonCount(0, 'data');
})->group('fast');

it('cannot have the date or the party switched off', function (): void {
    [$tenant] = SearchScenario::operator();

    // An operator who writes `false` into the settings for either of them gets
    // `true` back: a search page with no date and no party size is a catalogue
    // listing, which the home page already is.
    SearchScenario::filters($tenant, [
        SearchFilters::DATE => false,
        SearchFilters::PARTY => false,
        SearchFilters::PORT => false,
    ]);

    $filters = SearchFilters::for($tenant->refresh());

    expect($filters[SearchFilters::DATE])->toBeTrue()
        ->and($filters[SearchFilters::PARTY])->toBeTrue()
        ->and($filters[SearchFilters::PORT])->toBeFalse();
})->group('fast');

it('falls back to the design review defaults for an operator who never chose', function (): void {
    [$tenant] = SearchScenario::operator();

    // Most operators, most of the time. Date, port, party and type on; duration,
    // price and vessel off.
    expect(SearchFilters::enabled($tenant))->toBe([
        SearchFilters::DATE,
        SearchFilters::PORT,
        SearchFilters::PARTY,
        SearchFilters::TYPE,
    ]);
})->group('fast');

it('survives a settings blob written against an older shape', function (): void {
    [$tenant, $key] = SearchScenario::operator();
    SearchScenario::trip($tenant, 'still-renders');

    // A key that no longer exists and one that never did. This runs on **read**,
    // on a page a guest is looking at, so the unknown ones are dropped and the
    // missing ones default rather than throwing.
    SearchScenario::filters($tenant, ['port' => true, 'weather' => true, 'legacy_filter' => true]);

    getJson(SearchScenario::url(), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.filters_enabled', [
            SearchFilters::DATE,
            SearchFilters::PORT,
            SearchFilters::PARTY,
            SearchFilters::TYPE,
        ]);
})->group('fast');
