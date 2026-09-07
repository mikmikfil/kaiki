<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\TenantStatus;
use App\Models\Product;
use App\Support\Tenancy;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| #104: the page a search engine lands on and an operator sends a link to
|--------------------------------------------------------------------------
|
| Three groups of assertion, and only the first is ordinary.
|
| **Everything renders.** Title, summary, duration, meeting point with a map
| link, what is included and excluded, what to bring, the itinerary, the boat,
| the age bands and the cancellation policy — HOS-1's list, asserted item by
| item, because a section that silently stops rendering is invisible to every
| test that only checks the page is 200.
|
| **A trip that is not for sale is gone, not moved.** 404 rather than a redirect
| to the operator's home page: a redirect tells a crawler the page moved, so the
| trip's ranking follows it and the operator's landing page starts ranking for a
| trip they no longer run.
|
| **A quote product shows no price at all** (BKG-24), and the four lines above
| the date picker are exactly four (brand decision 3 of 2026-09-04).
|
*/

it('renders every section HOS-1 lists', function (): void {
    $tenant = OperatorPage::operator('full-trip');
    $product = TripPage::create($tenant);
    TripPage::departure($tenant, $product);

    $response = get(TripPage::url($tenant, $product, 'en'));

    $response->assertOk()
        // Identity and the facts row.
        ->assertSee('Sunset cruise', escape: false)
        ->assertSee('Three hours in the Saronic.', escape: false)
        ->assertSee(__('hosted.index.duration', ['minutes' => 480], 'en'), escape: false)
        // The operator's prose, through `BlockText` like everywhere else.
        ->assertSee('My grandfather built the boat.', escape: false)
        // The three lists.
        ->assertSee('A glass of wine', escape: false)
        ->assertSee('Hotel transfer', escape: false)
        ->assertSee('Sunscreen', escape: false)
        // The itinerary, in the locale being read.
        ->assertSee('Vlychada Bay', escape: false)
        // The meeting point, with somewhere to tap.
        ->assertSee('Zea Marina', escape: false)
        ->assertSee('At the blue kiosk.', escape: false)
        ->assertSee('google.com/maps', escape: false)
        // The boat, the bands and the terms.
        ->assertSee('Kalypso', escape: false)
        ->assertSee('Adult', escape: false)
        ->assertSee('Flexible', escape: false)
        ->assertSee(__('hosted.product.free_cancellation', ['hours' => 48], 'en'), escape: false)
        // A real departure, server-rendered — the same collection the `Event`
        // graph is built from, so the page and the structured data cannot
        // disagree about when the boat leaves.
        ->assertSee('20/12/2026', escape: false)
        ->assertSee('18:30', escape: false);
})->group('fast');

it('shows the price with the VAT sentence and never the rate', function (): void {
    $tenant = OperatorPage::operator('priced-trip');
    $product = TripPage::create($tenant);

    $body = (string) get(TripPage::url($tenant, $product, 'el'))->getContent();

    // Brand decision 4: the sentence, and the rate only ever on the invoice —
    // which is why the open VAT-rate question with the accountant does not
    // block this page.
    expect($body)->toContain('Στην τιμή περιλαμβάνεται ΦΠΑ')
        ->and($body)->toContain('45,00')
        ->and($body)->not->toContain('24%')
        ->and($body)->not->toContain('13%');
})->group('fast');

it('puts exactly four lines above the date picker', function (): void {
    $tenant = OperatorPage::operator('four-lines');
    $product = TripPage::create($tenant);

    $body = (string) get(TripPage::url($tenant, $product, 'en'))->getContent();

    // Brand decision 3, and the temptation this asserts against is adding a
    // photograph because the space looks empty. It looked empty in the mockup
    // too, and the decision was made against exactly that.
    expect(substr_count($body, '<div><dt>'))->toBe(4)
        ->and($body)->toContain(__('hosted.product.booking.port', [], 'en'))
        ->and($body)->toContain(__('hosted.product.booking.vessel', [], 'en'));
})->group('fast');

it('shows no price and offers an enquiry for a quote product', function (): void {
    $tenant = OperatorPage::operator('quote-trip');

    $product = Tenancy::forTenant($tenant, static fn (): Product => Product::factory()->quote()->create([
        'slug' => 'private-charter',
        'title' => ['el' => 'Ιδιωτική ναύλωση', 'en' => 'Private charter'],
        // A stale price left behind by a mode change. BKG-24 says a quote
        // product never shows a price, so the column must not leak one.
        'price_from_cents' => 90000,
    ]));

    $body = (string) get(TripPage::url($tenant, $product, 'en'))->getContent();

    expect($body)->toContain('Private charter')
        ->and($body)->not->toContain('900')
        ->and($body)->not->toContain(__('hosted.product.price.from', [], 'en'))
        ->and($body)->toContain('data-kaiki-mount="enquiry"')
        ->and($body)->toContain(__('hosted.product.booking.enquiry_fallback', [], 'en'));
})->group('fast');

it('404s an inactive trip rather than redirecting to the home page', function (): void {
    $tenant = OperatorPage::operator('gone-trip');
    $product = TripPage::create($tenant);

    Tenancy::forTenant($tenant, static function () use ($product): void {
        $product->forceFill(['status' => ProductStatus::Inactive])->save();
    });

    // Not a 301 and not a 302: a redirect tells a crawler the trip moved to the
    // landing page, and the landing page then ranks for a trip nobody sells.
    get(TripPage::url($tenant, $product))->assertNotFound();
})->group('fast');

it('404s a trip that was deleted, and one that belongs to another operator', function (): void {
    $tenant = OperatorPage::operator('mine-trip');
    $other = OperatorPage::operator('theirs-trip');

    $mine = TripPage::create($tenant);
    $theirs = TripPage::create($other, ['slug' => 'their-cruise']);

    Tenancy::forTenant($tenant, static fn () => $mine->delete());

    get(TripPage::url($tenant, $mine))->assertNotFound();

    // SEC-1 and SEC-2: another operator's trip is indistinguishable from one
    // that never existed. A 403 would confirm it is real and belongs to
    // somebody else.
    get(HostedRequest::url('/mine-trip/their-cruise'))->assertNotFound();
    get(HostedRequest::url('/mine-trip/no-such-trip'))->assertNotFound();

    // And it still renders on its own operator's page, so the 404 above is
    // isolation rather than the fixture being broken.
    get(TripPage::url($other, $theirs))->assertOk();
})->group('fast');

it('serves a read-only operator the whole page with the booking area replaced', function (): void {
    $tenant = OperatorPage::operator('lapsed-trip');
    $product = TripPage::create($tenant);

    $tenant->forceFill(['status' => TenantStatus::ReadOnly])->save();

    $response = get(TripPage::url($tenant, $product, 'en'));

    // HOS-10 and SAA-7's asymmetry: the pressure lands on the operator who owes
    // money, never on the tourist reading about a boat trip.
    $response->assertOk()
        ->assertSee('Sunset cruise', escape: false)
        ->assertSee('Kalypso', escape: false)
        ->assertSee(__('hosted.read_only', ['email' => $tenant->email], 'en'), escape: false)
        ->assertDontSee('data-kaiki-mount', escape: false);
})->group('fast');

it('keeps the legal page reachable for an operator whose trip is slugged legal', function (): void {
    $tenant = OperatorPage::operator('legal-clash');
    TripPage::create($tenant, ['slug' => 'legal']);

    // Both routes match two segments and Laravel takes the first registered, so
    // `/legal` is the legal page and the trip at that slug is shadowed. The
    // trade is deliberate — a shadowed trip is one operator renaming a slug; a
    // shadowed legal page is HOS-9 unreachable for everybody — and it is
    // asserted rather than left to be discovered.
    get(HostedRequest::url('/legal-clash/legal'))
        ->assertOk()
        ->assertSee(__('hosted.legal.title', [], 'el'), escape: false);
})->group('fast');
