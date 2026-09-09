<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\HostedPageCsp;
use App\Models\Port;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| The meeting point, drawn (HOS-2, HOS-8)
|--------------------------------------------------------------------------
|
| Two things have to agree or the map is a grey box on somebody else's page: the
| `src` in the markup and the `frame-src` in the header. They are asserted
| together here, against the same constant, because a mismatch fails silently in
| a browser and loudly nowhere.
|
| The third property is a subtraction. A port with no coordinates — an address
| alone, or a short link the operator pasted — gets **no** frame: the embed
| takes a bounding box rather than a search term, and no provider renders a
| `goo.gl` URL inside a frame. A refusal in a box is worse than the link alone
| on the section that tells a guest where to stand at nine in the morning.
|
| What none of this can assert is that the provider still serves an embeddable
| page at that URL. It is why these tests were all green while every trip page
| showed a void: Google's keyless `output=embed` began answering 404 with
| `X-Frame-Options: SAMEORIGIN`, and nothing in a test suite notices a third
| party changing its mind. That check is a pair of human eyes on the page.
|
*/

/**
 * Change the trip's meeting point, keeping the rest of the fixture furnished.
 *
 * @param  array<string, mixed>  $attributes
 */
function repoint(Tenant $tenant, array $attributes): void
{
    Tenancy::forTenant($tenant, static function () use ($attributes): void {
        Port::query()->firstOrFail()->forceFill($attributes)->save();
    });
}

it('draws the meeting point, and still offers the link', function (): void {
    $tenant = OperatorPage::operator('map-drawn');
    $product = TripPage::create($tenant);

    $body = get(TripPage::url($tenant, $product), HostedRequest::headers($tenant->slug))
        ->assertOk()
        ->getContent();

    expect($body)->toContain(HostedPageCsp::MAPS_ORIGIN . '/export/embed.html')
        ->and($body)->toContain('loading="lazy"')
        // A bounding box and a pin in the middle of it, not a search term.
        ->and($body)->toContain('bbox=')
        ->and($body)->toContain('marker=')
        // The link survives. On a phone the useful action is handing the
        // address to whatever does turn-by-turn, which a frame cannot do.
        ->and($body)->toContain('maps/search/');
});

it('boxes the meeting point tightly enough to recognise the street', function (): void {
    $tenant = OperatorPage::operator('map-bbox');

    $port = Tenancy::forTenant($tenant, static fn (): Port => Port::factory()->create([
        'tenant_id' => $tenant->id,
        'lat' => '37.9339',
        'lng' => '23.6469',
    ]));

    $url = (string) $port->mapsEmbedUrl();

    // Six decimals and the corners either side of the pin.
    expect($url)
        ->toContain('bbox=' . rawurlencode('23.6429,37.9319,23.6509,37.9359'))
        ->and($url)->toContain('marker=' . rawurlencode('37.9339,23.6469'))
        // Plain notation, never exponential: `(string) 1.0E-5` is a corner a
        // bounding box parser reads as zero.
        ->and($url)->not->toContain('E-')
        ->and($url)->not->toContain('e-');
});

it('serves a policy that permits exactly the origin it framed', function (): void {
    $tenant = OperatorPage::operator('map-csp');
    $product = TripPage::create($tenant);

    $csp = (string) get(TripPage::url($tenant, $product), HostedRequest::headers($tenant->slug))
        ->assertOk()
        ->headers->get('Content-Security-Policy');

    expect($csp)->toContain('frame-src ' . HostedPageCsp::MAPS_ORIGIN)
        // Narrow on purpose: a wildcard would admit every Google property,
        // including the ones that host somebody else's uploads.
        ->and($csp)->not->toContain('*.google.com')
        // A hosted page still frames out and is never framed in.
        ->and($csp)->toContain("frame-ancestors 'none'");
});

it('draws no map when the operator pasted a short link', function (): void {
    $tenant = OperatorPage::operator('map-shortlink');
    $product = TripPage::create($tenant);

    repoint($tenant, [
        'lat' => null,
        'lng' => null,
        'address' => null,
        'maps_url' => 'https://maps.app.goo.gl/abcdef',
    ]);

    $body = get(TripPage::url($tenant, $product), HostedRequest::headers($tenant->slug))
        ->assertOk()
        ->getContent();

    // No provider renders a short link inside a frame, so the page keeps the
    // link and shows no box rather than a broken one.
    expect($body)->not->toContain('/export/embed.html')
        ->and($body)->toContain('maps.app.goo.gl');
});

it('draws no map for an address with no coordinates, and keeps the link', function (): void {
    $tenant = OperatorPage::operator('map-address');
    $product = TripPage::create($tenant);

    repoint($tenant, ['lat' => null, 'lng' => null, 'maps_url' => null, 'address' => 'Ακτή Θεμιστοκλέους, Πειραιάς']);

    $body = get(TripPage::url($tenant, $product), HostedRequest::headers($tenant->slug))
        ->assertOk()
        ->getContent();

    // The embed takes a bounding box, so there is nothing to geocode it with.
    // The address is still printed and still links out to a map that can.
    expect($body)->not->toContain('/export/embed.html')
        ->and($body)->toContain('Ακτή Θεμιστοκλέους')
        ->and($body)->toContain('maps/search/');
});

it('draws nothing for a port with no location at all', function (): void {
    $tenant = OperatorPage::operator('map-nowhere');
    $product = TripPage::create($tenant);

    repoint($tenant, ['lat' => null, 'lng' => null, 'address' => null, 'maps_url' => null]);

    $body = get(TripPage::url($tenant, $product), HostedRequest::headers($tenant->slug))
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('/export/embed.html');
});
