<?php

declare(strict_types=1);

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| #104: two locales, and one canonical address per trip
|--------------------------------------------------------------------------
|
| HOS-5 wants `hreflang` alternates and a canonical per locale, and the product
| page is the first hosted surface with **two** addresses for one page:
| `PublicProductQuery::find()` answers a uuid as well as a slug, because the
| widget embed carries the uuid and the two are the same resource. That is
| exactly the duplicate-content problem a canonical exists to solve, so the
| canonical and the alternates are always the slug's.
|
*/

it('serves the trip in both locales, each pointing at the other', function (): void {
    $tenant = OperatorPage::operator('bilingual-trip');
    $product = TripPage::create($tenant);

    $greek = get(TripPage::url($tenant, $product, 'el'));
    $english = get(TripPage::url($tenant, $product, 'en'));

    $greek->assertOk()
        ->assertSee('Κρουαζιέρα ηλιοβασιλέματος', escape: false)
        ->assertSee('Το σκάφος το έφτιαξε ο παππούς μου.', escape: false)
        // The itinerary is translated too, and the `_geo` sidecar is shared —
        // §3.6's shape, joined by key rather than by position.
        ->assertSee('Όρμος Βλυχάδα', escape: false)
        ->assertSee('<html lang="el"', escape: false);

    $english->assertOk()
        ->assertSee('Sunset cruise', escape: false)
        ->assertSee('Vlychada Bay', escape: false)
        ->assertSee('<html lang="en"', escape: false);

    foreach ([$greek, $english] as $response) {
        $body = (string) $response->getContent();

        expect($body)->toContain('hreflang="el" href="http://book.kaiki.test/bilingual-trip/sunset-cruise?lang=el"')
            ->and($body)->toContain('hreflang="en" href="http://book.kaiki.test/bilingual-trip/sunset-cruise?lang=en"');
    }
})->group('fast');

it('carries the slug canonical for the locale being read', function (): void {
    $tenant = OperatorPage::operator('canonical-trip');
    $product = TripPage::create($tenant);

    $body = (string) get(TripPage::url($tenant, $product, 'el'))->getContent();

    expect($body)->toContain('<link rel="canonical" href="http://book.kaiki.test/canonical-trip/sunset-cruise?lang=el">');
})->group('fast');

it('canonicalises a uuid URL onto the slug', function (): void {
    $tenant = OperatorPage::operator('uuid-trip');
    $product = TripPage::create($tenant);

    // The same page, reached the way a widget embed addresses it. Without the
    // override it would advertise itself as its own canonical and compete with
    // the slug URL in search — two addresses, one page, and the operator's
    // ranking split between them.
    $response = get(HostedRequest::url('/uuid-trip/' . $product->uuid . '?lang=en'));

    $body = (string) $response->getContent();

    $response->assertOk()->assertSee('Sunset cruise', escape: false);

    expect($body)->toContain('<link rel="canonical" href="http://book.kaiki.test/uuid-trip/sunset-cruise?lang=en">')
        ->and($body)->toContain('hreflang="el" href="http://book.kaiki.test/uuid-trip/sunset-cruise?lang=el"')
        ->and($body)->not->toContain('canonical" href="http://book.kaiki.test/uuid-trip/' . $product->uuid);
})->group('fast');

it('falls back to the operator default locale, never to the browser header', function (): void {
    $tenant = OperatorPage::operator('default-locale-trip');
    $product = TripPage::create($tenant);

    $tenant->forceFill(['default_locale' => 'en'])->save();

    // A German tourist on a Greek operator's page gets the operator's choice
    // and a visible switch, not a language the operator never wrote.
    get(TripPage::url($tenant, $product))
        ->assertOk()
        ->assertSee('Sunset cruise', escape: false)
        ->assertSee('<html lang="en"', escape: false);

    // An unknown locale falls back rather than refusing: §4.2 gives the API a
    // `400 unsupported_locale` because an integrator benefits from being told,
    // and a tourist does not.
    get(TripPage::url($tenant, $product, 'de'))
        ->assertOk()
        ->assertSee('<html lang="en"', escape: false);
})->group('fast');

it('uppercases nothing, in either locale', function (): void {
    $tenant = OperatorPage::operator('no-caps-trip');
    $product = TripPage::create($tenant);
    TripPage::departure($tenant, $product);

    // I18N-2: Greek capitals drop their accents and browsers disagree about the
    // final sigma, so the rule is enforced on the stylesheet rather than
    // trusted to whoever writes the next section of it.
    foreach (['el', 'en'] as $locale) {
        $body = strtolower((string) get(TripPage::url($tenant, $product, $locale))->getContent());

        expect($body)->not->toContain('text-transform')
            ->and($body)->not->toContain('uppercase');
    }
})->group('fast');
