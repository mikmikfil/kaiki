<?php

declare(strict_types=1);

use App\Models\AgeBand;
use App\Models\Extra;
use App\Models\Port;
use App\Models\Product;
use App\Models\ProductExtra;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| NFR-6 — the catalogue reads issue a bounded number of queries
|--------------------------------------------------------------------------
|
| The failure this file exists to catch is invisible in every other test: a
| resource reaching for `$product->vessel` without an eager load is correct,
| passes its assertions, and costs one query per row. It only shows up as "the
| widget got slow" from an operator with a real catalogue, months later.
|
| So the assertion is a **ceiling, not an exact number**. Pinning the exact
| count turns a harmless extra query into a red build and teaches the next
| person to raise the number rather than look; a ceiling that a per-row query
| would blow through by an order of magnitude catches the thing that matters and
| tolerates the thing that does not.
|
*/

/**
 * @return list<string>
 */
function recordQueries(callable $callback): array
{
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $callback();

    DB::flushQueryLog();

    return $queries;
}

it('lists forty products without an N+1 across vessels or meeting points', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    Tenancy::forTenant($tenant, function (): void {
        $extra = Extra::factory()->create(['is_tenant_wide' => true]);

        foreach (range(1, 40) as $i) {
            $port = Port::factory()->create(['name' => ['el' => "Λιμάνι {$i}", 'en' => "Port {$i}"]]);
            $vessel = Vessel::factory()->create(['capacity_max' => 40]);

            $product = Product::factory()->create([
                'slug' => "trip-{$i}",
                'sort_order' => $i,
                'vessel_id' => $vessel->getKey(),
                'meeting_point_id' => $port->getKey(),
            ]);

            // Bands and extras on every row, so a resource that reached for
            // them from the list payload would show up here even though the
            // contract's `ProductSummary` does not carry them.
            AgeBand::factory()->create(['product_id' => $product->getKey()]);
            ProductExtra::factory()->create([
                'product_id' => $product->getKey(),
                'extra_id' => $extra->getKey(),
            ]);
        }
    });

    $queries = recordQueries(function () use ($key): void {
        getJson(
            CatalogRequest::url('/products', ['per_page' => 40]),
            ['Authorization' => "Bearer {$key}"],
        )->assertOk()->assertJsonCount(40, 'data');
    });

    // Forty products, and the count does not scale with them: the key lookup,
    // the tenant, the page of products, and one query each for the vessels and
    // the ports. A per-row load would be eighty more.
    expect(count($queries))->toBeLessThanOrEqual(12, implode("\n", $queries));
})->group('fast');

it('resolves one product in full without scaling with its bands or extras', function (): void {
    [$tenant, $key] = CatalogRequest::key();

    $product = Tenancy::forTenant($tenant, function (): Product {
        $product = Product::factory()->create();

        foreach (range(1, 12) as $i) {
            AgeBand::factory()->create([
                'product_id' => $product->getKey(),
                'code' => "band-{$i}",
                'is_base' => $i === 1,
                'sort_order' => $i,
            ]);

            $extra = Extra::factory()->create(['sort_order' => $i]);
            ProductExtra::factory()->create([
                'product_id' => $product->getKey(),
                'extra_id' => $extra->getKey(),
            ]);
        }

        return $product;
    });

    $queries = recordQueries(function () use ($key, $product): void {
        getJson(CatalogRequest::url("/products/{$product->uuid}"), ['Authorization' => "Bearer {$key}"])
            ->assertOk()
            ->assertJsonCount(12, 'data.age_bands')
            ->assertJsonCount(12, 'data.extras');
    });

    // Twelve bands and twelve extras resolve in a fixed handful of queries.
    // `OfferedExtrasResolver` is two of them and is called twice — once to know
    // which extras apply and once to load the models behind them — which is
    // where this ceiling has room to tighten if it ever matters.
    expect(count($queries))->toBeLessThanOrEqual(16, implode("\n", $queries));
})->group('fast');
