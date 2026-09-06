<?php

declare(strict_types=1);

use App\Enums\ApiScope;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| NFR-7 — availability does not scale with the range
|--------------------------------------------------------------------------
|
| `CheckSeatAvailability` documents a five-query budget for the engine itself:
| the vessel's departures across the range, its blocks, the product's active
| rate plans, and the seasons with their ranges. Every date is then decided in
| PHP.
|
| **The assertion here is not "five".** A request also pays for the API key, the
| tenant and the product with its relations, so an absolute number would be
| counting things NFR-7 is not about and would need editing every time the auth
| path changed — which is how a number becomes a lie.
|
| What NFR-7 actually protects against is a count that *grows with the range*,
| and that is asserted directly: a 62-day request issues exactly as many queries
| as a 1-day one. A per-date query would make the second number sixty-one
| higher, and no plausible refactor can hide that.
|
| ADR-0023 pulls the NFR-1 150 ms p95 benchmark forward to the close of M2, with
| a miss being the documented trigger to reopen it. Until that benchmark exists
| this is the standing evidence for the contract.
|
*/

/** @return list<string> */
function recordAvailabilityQueries(callable $callback): array
{
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $callback();

    DB::flushQueryLog();

    return $queries;
}

it('costs the same for 62 days as for one', function (): void {
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::AvailabilityRead]);

    $product = Tenancy::forTenant($tenant, function (): Product {
        $vessel = Vessel::factory()->create(['capacity_max' => 40]);

        $product = Product::factory()->create(['vessel_id' => $vessel->getKey(), 'max_pax' => 12]);

        AgeBand::factory()->create(['product_id' => $product->getKey()]);
        RatePlan::factory()->create(['product_id' => $product->getKey(), 'min_lead_time_hours' => 0]);

        // A sailing on every one of the sixty-two days, so a per-date query
        // would have sixty-two chances to appear.
        foreach (range(1, 62) as $offset) {
            $date = Carbon::now()->addDays($offset);

            // `at()` rather than hand-written UTC instants. A 62-day range
            // crosses the October clock change, and CNV-3's guard refuses a row
            // whose three time columns disagree — correctly: 09:00 local is a
            // different instant either side of it.
            Departure::factory()
                ->at($date->toDateString(), '09:00')
                ->create([
                    'product_id' => $product->getKey(),
                    'vessel_id' => $vessel->getKey(),
                    'capacity' => 12,
                ]);
        }

        return $product->refresh();
    });

    $url = static fn (int $days): string => CatalogRequest::url('/availability', [
        'product' => $product->uuid,
        'from' => Carbon::now()->addDay()->toDateString(),
        'to' => Carbon::now()->addDays($days)->toDateString(),
        'pax' => 2,
    ]);

    // Warmed first, and discarded. The **first** request on a key writes
    // `api_keys.last_used_at`, which #6's throttle then suppresses for the next
    // few minutes — so an unwarmed pair differs by one query for a reason that
    // has nothing to do with the range, in the direction that makes the short
    // request look more expensive.
    getJson($url(1), ['Authorization' => "Bearer {$key}"])->assertOk();

    $oneDay = recordAvailabilityQueries(function () use ($key, $url): void {
        getJson($url(1), ['Authorization' => "Bearer {$key}"])->assertOk()->assertJsonCount(1, 'data');
    });

    $wholeRange = recordAvailabilityQueries(function () use ($key, $url): void {
        getJson($url(62), ['Authorization' => "Bearer {$key}"])->assertOk()->assertJsonCount(62, 'data');
    });

    expect(count($wholeRange))->toBe(
        count($oneDay),
        '62 days cost ' . count($wholeRange) . ' queries and one day cost ' . count($oneDay) . ":\n"
        . implode("\n", $wholeRange),
    );

    // A ceiling as well, so the whole request stays small rather than merely
    // constant. The engine's own budget is five; the rest is the key, the
    // tenant and the product with its vessel, bands and plans.
    //
    // **Thirteen, raised from twelve by #80.** AVL-3.4's fourth occupation
    // source — a guest holding a private charter on the same boat — could not
    // be asked before `bookings` existed, so `VesselHoldSource` had no
    // implementation and cost nothing. It has one now, and it is one query for
    // the vessel across the whole range: the equality assertion above is what
    // proves it did not become one per date. Without it two guests can be at
    // the checkout for the same boat on the same afternoon, which is a worse
    // outcome than a thirteenth query.
    expect(count($wholeRange))->toBeLessThanOrEqual(13, implode("\n", $wholeRange));
})->group('fast');

it('costs the same for a 62-day charter calendar', function (): void {
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

        return $product->refresh();
    });

    $url = static fn (int $days): string => CatalogRequest::url('/availability', [
        'product' => $product->uuid,
        'from' => Carbon::now()->addDay()->toDateString(),
        'to' => Carbon::now()->addDays($days)->toDateString(),
    ]);

    getJson($url(1), ['Authorization' => "Bearer {$key}"])->assertOk();

    $oneDay = recordAvailabilityQueries(function () use ($key, $url): void {
        getJson($url(1), ['Authorization' => "Bearer {$key}"])->assertOk();
    });

    $wholeRange = recordAvailabilityQueries(function () use ($key, $url): void {
        getJson($url(62), ['Authorization' => "Bearer {$key}"])->assertOk()->assertJsonCount(62, 'data');
    });

    // `CheckVesselAvailability` loads the departures and the blocks once, so
    // "a 62-day charter calendar costs what one day costs" is its own docblock's
    // claim. This is the assertion behind it.
    expect(count($wholeRange))->toBe(count($oneDay), implode("\n", $wholeRange));
})->group('fast');

it('is cacheable for the window the widget assumes', function (): void {
    [$tenant, $key] = CatalogRequest::key(scopes: [ApiScope::AvailabilityRead]);

    $product = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create());

    // WGT-17 assumes a 60-second in-memory cache in the widget; §3.6 gives the
    // response 30, so the widget's window always sits inside the server's.
    getJson(CatalogRequest::url('/availability', [
        'product' => $product->uuid,
        'from' => Carbon::now()->addDay()->toDateString(),
        'to' => Carbon::now()->addDay()->toDateString(),
    ]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=30, public');
})->group('fast');
