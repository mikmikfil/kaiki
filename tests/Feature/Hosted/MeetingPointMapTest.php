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
| The third property is a subtraction. A port whose only location is a short link
| the operator pasted gets **no** frame: Google will not render a `goo.gl` URL
| inside one, and a refusal in a box is worse than the link alone on the section
| that tells a guest where to stand at nine in the morning.
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

    expect($body)->toContain('output=embed')
        ->and($body)->toContain('loading="lazy"')
        // The link survives. On a phone the useful action is handing the
        // address to whatever does turn-by-turn, which a frame cannot do.
        ->and($body)->toContain('maps/search/');
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

    // Google refuses a short link inside a frame, so the page keeps the link
    // and shows no box rather than a broken one.
    expect($body)->not->toContain('output=embed')
        ->and($body)->toContain('maps.app.goo.gl');
});

it('falls back to the address when there are no coordinates', function (): void {
    $tenant = OperatorPage::operator('map-address');
    $product = TripPage::create($tenant);

    repoint($tenant, ['lat' => null, 'lng' => null]);

    $body = get(TripPage::url($tenant, $product), HostedRequest::headers($tenant->slug))
        ->assertOk()
        ->getContent();

    expect($body)->toContain('output=embed');
});

it('draws nothing for a port with no location at all', function (): void {
    $tenant = OperatorPage::operator('map-nowhere');
    $product = TripPage::create($tenant);

    repoint($tenant, ['lat' => null, 'lng' => null, 'address' => null, 'maps_url' => null]);

    $body = get(TripPage::url($tenant, $product), HostedRequest::headers($tenant->slug))
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('output=embed');
});
