<?php

declare(strict_types=1);

use function Pest\Laravel\get;

use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| #104: HOS-4, asserted as an absence
|--------------------------------------------------------------------------
|
| *"Hosted pages are server-rendered Blade, work without JavaScript for all
| content, and mount the widget only for the booking interaction."*
|
| The only test that can prove that is one asserting what is **not** in the
| markup: no script the page depends on, and every section present in the HTML
| the server sent. A crawler runs no JavaScript, and HOS-2's whole point is that
| these pages are what search engines see; a visitor in a harbour on one bar of
| signal is the other half.
|
| The failure this guards against is not hypothetical. #101 found Livewire
| appending fifty kilobytes of JavaScript and a CSRF token to a cacheable public
| page, and it found it with exactly this assertion.
|
*/

it('renders every section in the HTML the server sent', function (): void {
    $tenant = OperatorPage::operator('no-js-trip');
    $product = TripPage::create($tenant);
    TripPage::departure($tenant, $product);

    $body = (string) get(TripPage::url($tenant, $product, 'en'))->getContent();

    // Nothing here was fetched, hydrated or rendered by a client. If any of it
    // moved into the widget, this is the test that goes red.
    expect($body)->toContain('My grandfather built the boat.')
        ->and($body)->toContain('Zea Marina')
        ->and($body)->toContain('At the blue kiosk.')
        ->and($body)->toContain('Flexible')
        ->and($body)->toContain('20/12/2026')
        ->and($body)->toContain('A glass of wine');
})->group('fast');

it('carries no script but the structured data, and no Livewire anywhere', function (): void {
    $tenant = OperatorPage::operator('no-js-scripts');
    $product = TripPage::create($tenant);

    $body = (string) get(TripPage::url($tenant, $product, 'en'))->getContent();

    // Every `<script` on the page is either the structured data, which executes
    // nothing and is the one thing HOS-2 requires, or the widget bundle, which
    // is the page's whole reason to carry script at all.
    //
    // The tag the widget needs was anticipated here from the start — this test
    // said it "arrives in #106 and mounts into the node below rather than
    // replacing this claim" — and the claim is unchanged: nothing on this page
    // is hydrated, and there is no framework runtime, no CSRF token and no
    // second script of anybody's making.
    $widgetTags = substr_count($body, 'kaiki-widget.js');

    expect($widgetTags)->toBe(1)
        ->and(substr_count($body, '<script'))
        ->toBe(substr_count($body, '<script type="application/ld+json"') + $widgetTags)
        ->and($body)->not->toContain('livewire')
        ->and($body)->not->toContain('csrf-token');
})->group('fast');

it('replaces the booking flow with a way to reach the operator', function (): void {
    $tenant = OperatorPage::operator('no-js-fallback');
    $product = TripPage::create($tenant);

    $tenant->forceFill(['phone' => '+302104176000'])->save();

    $body = (string) get(TripPage::url($tenant, $product, 'en'))->getContent();

    // The mount node's contents are the no-JavaScript answer and stay in the
    // markup until the widget replaces them — so a blocked script, a crawler
    // and a bad connection all get a way to book rather than an empty box.
    expect($body)->toContain('data-kaiki-mount="booking"')
        ->and($body)->toContain('data-kaiki-product="' . $product->uuid . '"')
        ->and($body)->toContain(__('hosted.product.booking.fallback', [], 'en'))
        ->and($body)->toContain('mailto:' . $tenant->email)
        ->and($body)->toContain('tel:+302104176000');
})->group('fast');
