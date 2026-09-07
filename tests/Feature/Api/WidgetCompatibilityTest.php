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
use Tests\Support\Api\WidgetExpectations;

/*
|--------------------------------------------------------------------------
| The widget against /api/v1 — ADR-0011's third release gate, issue 110
|--------------------------------------------------------------------------
|
| The alias is repointed on release and an operator's `<script>` tag never
| changes, which is the whole point of it — and also the reason the blast radius
| of a bad release is every embed in production at once. ADR-0011 names three
| gates before the alias moves: the size budget, a Playwright run on the built
| artefact, and this.
|
| **The expectations are read out of the widget's own source.** A hand-written
| list of field names would be a third opinion sitting between the widget and
| the API, agreeing with neither the day somebody changes one. Parsing the
| TypeScript interfaces means this fails when the widget begins wanting a field
| the API does not send — which is the release that breaks every page quietly,
| because a missing field is `undefined` and `undefined` renders as nothing.
|
| It is a compatibility test, not a contract test: `OpenApiDriftTest` already
| holds `docs/api.md` and the routes together. This holds the *client* to them.
|
*/

function widgetSource(string $relative): string
{
    return base_path("packages/widget/src/{$relative}");
}

/**
 * A tenant with one sellable trip and one sailing, and a key that may read it.
 *
 * @return array{0: string, 1: Product}
 */
function widgetCompatFixture(): array
{
    [$tenant, $key] = CatalogRequest::key(scopes: [
        ApiScope::ProductsRead,
        ApiScope::AvailabilityRead,
        ApiScope::BrandingRead,
    ]);

    $product = Tenancy::forTenant($tenant, function (): Product {
        $vessel = Vessel::factory()->create(['capacity_max' => 40]);

        $product = Product::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'max_pax' => 12,
        ]);

        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        RatePlan::factory()->create([
            'product_id' => $product->getKey(),
            'min_lead_time_hours' => 0,
            'max_advance_days' => 365,
        ]);

        Departure::factory()
            ->at(Carbon::now()->addDays(30)->toDateString(), '09:00')
            ->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $vessel->getKey(),
                'capacity' => 12,
                'seats_sold' => 0,
                'status' => DepartureStatus::Scheduled,
            ]);

        return $product->refresh();
    });

    return [$key, $product];
}

it('sends every field the list mount declares it reads', function (): void {
    [$key] = widgetCompatFixture();

    $expected = WidgetExpectations::fields(widgetSource('mounts/list/ListMount.tsx'), 'ProductCard');

    expect($expected)->not->toBeEmpty();

    $card = getJson(CatalogRequest::url('/products'), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->json('data.0');

    expect($card)->toBeArray();

    // `toHaveKeys` rather than an equality: the API may send more than the
    // widget reads — that is what a version-tolerant client looks like — but
    // never less.
    expect(array_keys((array) $card))->toContain(...$expected);
})->group('fast');

it('sends every field the calendar mount declares it reads', function (): void {
    [$key, $product] = widgetCompatFixture();

    $expected = WidgetExpectations::fields(widgetSource('mounts/calendar/CalendarMount.tsx'), 'AvailabilityDay');

    expect($expected)->not->toBeEmpty();

    $day = getJson(CatalogRequest::url('/availability', [
        'product' => $product->uuid,
        'from' => Carbon::now()->addDays(30)->toDateString(),
        'to' => Carbon::now()->addDays(30)->toDateString(),
    ]), ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->json('data.0');

    expect(array_keys((array) $day))->toContain(...$expected);
})->group('fast');

it('sends every branding field the widget turns into a custom property', function (): void {
    [$key] = widgetCompatFixture();

    $payload = (array) getJson('/api/v1/branding', ['Authorization' => "Bearer {$key}"])
        ->assertOk()
        ->json('data');

    $expected = WidgetExpectations::fields(widgetSource('branding.ts'), 'BrandPayload');

    expect(array_keys($payload))->toContain(...$expected);

    // WGT-9 fixes these five names. A renamed colour is a widget that paints an
    // operator's page in the platform defaults and reports no error at all.
    expect(array_keys((array) $payload['colors']))
        ->toContain(...WidgetExpectations::nested(widgetSource('branding.ts'), 'BrandPayload', 'colors'));

    expect(array_keys((array) $payload['font']))
        ->toContain(...WidgetExpectations::nested(widgetSource('branding.ts'), 'BrandPayload', 'font'));
})->group('fast');

it('answers the paths the widget actually calls, and no others', function (): void {
    // The set of endpoints the bundle can reach, read off the source. A new one
    // appearing here without a route is a release that 404s on somebody's page;
    // this is also the list `docs/widget.md` documents for a CSP.
    // Recursively, because `glob()` does not do `**` — and a mount in a
    // subdirectory calling an undocumented endpoint is exactly what this looks
    // for.
    $sources = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('packages/widget/src'), FilesystemIterator::SKIP_DOTS),
    );

    $paths = [];

    foreach ($sources as $file) {
        if (! preg_match('/\.tsx?$/', (string) $file)) {
            continue;
        }

        preg_match_all(
            // The type parameter is optional: a call whose response is thrown
            // away is written without one, and it reaches the network all the
            // same.
            '/\.(?:get|post)(?:<[^>]*>)?\(\s*[`\']\/(?P<path>[a-z-]+)/i',
            (string) file_get_contents((string) $file),
            $found,
        );

        $paths = [...$paths, ...$found['path']];
    }

    $paths = array_values(array_unique($paths));

    sort($paths);

    // If this list grows, the CSP snippet in docs/widget.md and the scopes an
    // operator's publishable key needs both have to grow with it.
    expect($paths)->toBe(['availability', 'bookings', 'branding', 'enquiries', 'products']);
})->group('fast');
