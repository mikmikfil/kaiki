<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

use Tests\Support\Api\SearchScenario;

/*
|--------------------------------------------------------------------------
| #105 — the search does not scale with the catalogue
|--------------------------------------------------------------------------
|
| The acceptance criterion names the failure: *"it does not become N+1 across
| products"*. It is the obvious way to build this feature — loop the catalogue,
| call the availability engine per trip, price each one — and it is invisible in
| development, where every operator has two products.
|
| **The assertion is not an absolute number.** A request also pays for the API
| key, the tenant and the middleware, so a fixed count would be measuring things
| this criterion is not about and would need editing every time the auth path
| changed — which is how a number becomes a lie. `AvailabilityQueryCountTest`
| made the same argument about the range, and this is the same shape of claim
| about the catalogue:
|
| **a search across twelve trips issues exactly as many queries as one across
| two.** A per-product call would make the second number ten higher, and no
| plausible refactor can hide that.
|
*/

/** @return list<string> */
function recordSearchQueries(callable $callback): array
{
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $callback();

    DB::flushQueryLog();

    return $queries;
}

it('issues the same number of queries for twelve trips as for two', function (): void {
    [$small, $smallKey] = SearchScenario::operator();
    [$large, $largeKey] = SearchScenario::operator();

    foreach (range(1, 2) as $n) {
        SearchScenario::trip($small, "small-{$n}", adultPriceCents: 1000 * $n);
    }

    foreach (range(1, 12) as $n) {
        SearchScenario::trip($large, "large-{$n}", adultPriceCents: 1000 * $n);
    }

    $twoTrips = recordSearchQueries(static function () use ($smallKey): void {
        getJson(SearchScenario::url(['pax' => 2]), ['Authorization' => "Bearer {$smallKey}"])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    $twelveTrips = recordSearchQueries(static function () use ($largeKey): void {
        getJson(SearchScenario::url(['pax' => 2]), ['Authorization' => "Bearer {$largeKey}"])
            ->assertOk()
            ->assertJsonCount(12, 'data');
    });

    expect(count($twelveTrips))->toBe(count($twoTrips), sprintf(
        "The search scales with the catalogue.\n2 trips: %d queries\n12 trips: %d queries\n\n%s",
        count($twoTrips),
        count($twelveTrips),
        implode("\n", array_slice($twelveTrips, 0, 40)),
    ));
})->group('fast');

it('prices a party without a query per product', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    foreach (range(1, 8) as $n) {
        SearchScenario::trip($tenant, "priced-{$n}", adultPriceCents: 1000 * $n);
    }

    $queries = recordSearchQueries(static function () use ($key): void {
        getJson(SearchScenario::url(['pax' => 4]), ['Authorization' => "Bearer {$key}"])
            ->assertOk()
            ->assertJsonCount(8, 'data')
            // Priced, not merely listed: a count test that let the price be null
            // would pass on an endpoint that had stopped computing one.
            ->assertJsonPath('data.0.party_price_cents', 4000);
    });

    // `rate_plan_prices` is where the naive version pays per product:
    // `PaxLineBuilder` reads `$plan->prices()`, which queries every call unless
    // the caller eager-loaded them. One read for the whole catalogue is the
    // difference between a search and a search that an operator complains about.
    $priceQueries = array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'rate_plan_prices'),
    );

    expect(count($priceQueries))->toBeLessThanOrEqual(1);
})->group('fast');

it('asks about vessel occupancy only when a charter is in the results', function (): void {
    [$tenant, $key] = SearchScenario::operator();

    foreach (range(1, 4) as $n) {
        SearchScenario::trip($tenant, "seat-{$n}");
    }

    $queries = recordSearchQueries(static function () use ($key): void {
        getJson(SearchScenario::url(['pax' => 2]), ['Authorization' => "Bearer {$key}"])->assertOk();
    });

    // A catalogue of shared trips asks nothing about `vessel_blocks`: for those
    // products the departure's own `is_blocked` is the cached answer, and two
    // queries answering a question nobody put are two queries.
    $blockQueries = array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'vessel_blocks'),
    );

    expect($blockQueries)->toBe([]);
})->group('fast');
