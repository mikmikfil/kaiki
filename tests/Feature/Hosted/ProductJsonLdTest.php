<?php

declare(strict_types=1);

use App\Enums\DepartureStatus;
use App\Models\Product;
use App\Support\Tenancy;

use function Pest\Laravel\get;

use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| #104: the Product + Event graph, asserted by parsing it
|--------------------------------------------------------------------------
|
| HOS-2 (FIXED) asks for **both** types, and the reason is that a boat trip is
| both things: `Product` earns the price in a search result, `Event` earns the
| date and the place. Only one of them is a listing missing half of what makes
| it useful.
|
| Every assertion here decodes the actual contents of the actual script element.
| A JSON-LD document that does not parse is discarded by Google **silently**, so
| a test matching the string `"Event"` would pass on markup that achieves
| nothing — and would go on passing on the day it broke.
|
*/

/**
 * The decoded `@graph` of a product page, by type.
 *
 * @return array{document: array<string, mixed>, product: array<string, mixed>|null, events: list<array<string, mixed>>}
 */
function graphOn(string $url): array
{
    $body = (string) get($url)->getContent();

    preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $body, $matches);

    foreach ($matches[1] as $json) {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! isset($decoded['@graph'])) {
            // The FAQ block of #103 is on this page too and is a `FAQPage`
            // rather than a graph. Walking past it rather than assuming an
            // order is what keeps this test about the graph.
            continue;
        }

        $nodes = is_array($decoded['@graph']) ? $decoded['@graph'] : [];

        return [
            'document' => $decoded,
            'product' => collect($nodes)->firstWhere('@type', 'Product'),
            'events' => collect($nodes)->where('@type', 'Event')->values()->all(),
        ];
    }

    return ['document' => [], 'product' => null, 'events' => []];
}

it('emits one Product and one Event per departure, in one graph that parses', function (): void {
    $tenant = OperatorPage::operator('graph-trip');
    $product = TripPage::create($tenant);

    TripPage::departure($tenant, $product, '2026-12-20', '18:30');
    TripPage::departure($tenant, $product, '2026-12-21', '18:30');

    $graph = graphOn(TripPage::url($tenant, $product, 'en'));

    expect($graph['document']['@context'] ?? null)->toBe('https://schema.org')
        ->and($graph['product'])->not->toBeNull()
        ->and($graph['product']['name'] ?? null)->toBe('Sunset cruise')
        ->and($graph['product']['sku'] ?? null)->toBe('sunset-cruise')
        ->and($graph['events'])->toHaveCount(2);

    // The price is an `AggregateOffer`'s `lowPrice`, not a flat `Offer`, because
    // `price_from_cents` is the cheapest band on the cheapest plan — stating it
    // as *the* price puts a number in a search result that a family of four
    // will never be charged.
    expect($graph['product']['offers']['@type'] ?? null)->toBe('AggregateOffer')
        ->and($graph['product']['offers']['lowPrice'] ?? null)->toBe('45.00')
        ->and($graph['product']['offers']['priceCurrency'] ?? null)->toBe('EUR');
})->group('fast');

it('gives every Event the operator local time, with its offset', function (): void {
    $tenant = OperatorPage::operator('event-times');
    $product = TripPage::create($tenant);

    TripPage::departure($tenant, $product, '2026-12-20', '18:30');

    $events = graphOn(TripPage::url($tenant, $product, 'en'))['events'];

    // `starts_at_utc` is an instant; a search engine showing "18:30" has to know
    // where. December is +02:00 in Athens — the winter offset, deliberately, so
    // a hardcoded +03:00 would fail here rather than in July.
    expect($events[0]['startDate'] ?? null)->toBe('2026-12-20T18:30:00+02:00')
        ->and($events[0]['endDate'] ?? null)->toBe('2026-12-20T21:30:00+02:00')
        ->and($events[0]['eventAttendanceMode'] ?? null)
        // Without it Google guesses, and since 2020 the guess for an event with
        // no venue has been "online" — a boat trip listed as a webinar.
        ->toBe('https://schema.org/OfflineEventAttendanceMode')
        ->and($events[0]['location']['@type'] ?? null)->toBe('Place')
        ->and($events[0]['location']['name'] ?? null)->toBe('Zea Marina')
        ->and($events[0]['location']['geo']['latitude'] ?? null)->toBe(37.9339);
})->group('fast');

it('marks a full departure sold out rather than dropping it', function (): void {
    $tenant = OperatorPage::operator('sold-out-graph');
    $product = TripPage::create($tenant);

    $open = TripPage::departure($tenant, $product, '2026-12-20', '18:30');
    $full = TripPage::departure($tenant, $product, '2026-12-21', '18:30');

    Tenancy::forTenant($tenant, static function () use ($full): void {
        // Capacity 12, all committed. A guest sent from a search result to a
        // full boat is a wasted click for them and a message for the operator.
        $full->forceFill(['seats_sold' => 12])->save();
    });

    $events = collect(graphOn(TripPage::url($tenant, $product, 'en'))['events'])
        ->keyBy(fn (array $event): string => (string) $event['startDate']);

    $openStart = $open->starts_at_utc->copy()->setTimezone($tenant->timezone)->toIso8601String();
    $fullStart = $full->starts_at_utc->copy()->setTimezone($tenant->timezone)->toIso8601String();

    expect($events[$openStart]['offers']['availability'] ?? null)->toBe('https://schema.org/InStock')
        ->and($events[$fullStart]['offers']['availability'] ?? null)->toBe('https://schema.org/SoldOut');
})->group('fast');

it('keeps a cancelled or blocked departure out of the graph entirely', function (): void {
    $tenant = OperatorPage::operator('cancelled-graph');
    $product = TripPage::create($tenant);

    $live = TripPage::departure($tenant, $product, '2026-12-20', '18:30');
    $cancelled = TripPage::departure($tenant, $product, '2026-12-21', '18:30');
    $blocked = TripPage::departure($tenant, $product, '2026-12-22', '18:30');

    Tenancy::forTenant($tenant, static function () use ($cancelled, $blocked): void {
        $cancelled->forceFill(['status' => DepartureStatus::Cancelled, 'cancelled_at' => now()])->save();
        $blocked->forceFill(['is_blocked' => true])->save();
    });

    $events = graphOn(TripPage::url($tenant, $product, 'en'))['events'];

    // An `Event` for a sailing that is not running is worse than no `Event`,
    // because a search engine will show it — with a date, a place and a price.
    expect($events)->toHaveCount(1)
        ->and($events[0]['startDate'] ?? null)
        ->toBe($live->starts_at_utc->copy()->setTimezone($tenant->timezone)->toIso8601String());
})->group('fast');

it('puts no price anywhere in the graph of a quote product', function (): void {
    $tenant = OperatorPage::operator('quote-graph');

    $product = Tenancy::forTenant($tenant, static fn (): Product => Product::factory()->quote()->create([
        'slug' => 'private-charter',
        'title' => ['el' => 'Ιδιωτική ναύλωση', 'en' => 'Private charter'],
        'price_from_cents' => 90000,
    ]));

    $graph = graphOn(TripPage::url($tenant, $product, 'en'));

    // BKG-24 seen from the search engine's side. A `Product` with `price: 0` is
    // a free boat trip as far as Google is concerned, and that is a listing an
    // operator cannot undo — so the key is absent rather than empty.
    expect($graph['product'])->not->toBeNull()
        ->and($graph['product'])->not->toHaveKey('offers')
        ->and(json_encode($graph['document']))->not->toContain('900');
})->group('fast');

it('cannot be closed early by an operator who pastes a closing script tag', function (): void {
    $tenant = OperatorPage::operator('escape-graph');

    $product = TripPage::create($tenant, [
        'summary' => [
            'el' => 'Περίληψη </script><script>alert(1)</script>',
            'en' => 'Summary </script><script>alert(1)</script>',
        ],
    ]);

    $graph = graphOn(TripPage::url($tenant, $product, 'en'));

    // The graph still parses — which it would not if the operator's text had
    // closed the element — and the page carries no script of the operator's
    // making, only the two `application/ld+json` blocks this page emits.
    expect($graph['product']['description'] ?? null)->toContain('</script>');

    $body = (string) get(TripPage::url($tenant, $product, 'en'))->getContent();

    expect(substr_count($body, '<script'))->toBe(substr_count($body, '<script type="application/ld+json"'));
})->group('fast');

it('carries the nonce the policy requires', function (): void {
    $tenant = OperatorPage::operator('nonce-graph');
    $product = TripPage::create($tenant);

    $response = get(TripPage::url($tenant, $product, 'en'));
    $body = (string) $response->getContent();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    // HOS-8 has no `unsafe-inline`, and `script-src` applies to a script element
    // whatever its type. An un-nonced graph is dropped by the browser unread.
    expect(preg_match('#<script type="application/ld\+json" nonce="([^"]+)"#', $body, $matches))->toBe(1);

    expect($csp)->toContain("'nonce-{$matches[1]}'");
})->group('fast');
