<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Product;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| #132: a picture on every trip card
|--------------------------------------------------------------------------
|
| The card has two states and both are deliberate. A trip with a photograph
| shows it; a trip without gets a tinted panel in the operator's own colour,
| because a new operator sees that on every card until they upload something,
| and a broken image or a stock photograph of somebody else's boat is worse.
|
| Asserted here rather than left to the eye, because the whole mechanism is one
| lookup in a partial: `$product->images[0]['path']`. The column holds
| `{path, alt}` by convention and nothing enforces the shape, so a rename of
| that key takes every photograph off every card and leaves a page that still
| returns 200 and still passes every other test in this directory.
|
| The `alt` is empty on purpose, and that is asserted too. The link around the
| image is `aria-hidden` and the heading beneath it is the same link: a screen
| reader that announced both would read every trip on the page twice.
|
*/

it('shows the photograph on the card, and hides it from the accessibility tree', function (): void {
    $tenant = OperatorPage::operator('with-a-picture');

    OperatorPage::as($tenant, function (): void {
        Product::factory()->create([
            'status' => ProductStatus::Active,
            'title' => ['el' => 'Σπηλιές και όρμοι', 'en' => 'Caves and coves'],
            'images' => [[
                'path' => 'products/9/spilies-kai-ormoi.jpg',
                'alt' => ['el' => 'Βραχώδης αψίδα', 'en' => 'A rock arch'],
            ]],
        ]);
    });

    $body = (string) get(HostedRequest::url('/with-a-picture'))->getContent();

    expect($body)->toContain('<img src="/storage/products/9/spilies-kai-ormoi.jpg" alt="" loading="lazy">')
        ->and($body)->toContain('aria-hidden="true"')
        // Once, in the stylesheet every page carries — never on a card that has
        // a picture to show.
        ->and(substr_count($body, 'is-empty'))->toBe(1);
})->group('fast');

it('falls back to the tinted panel for a trip with no photograph', function (): void {
    $tenant = OperatorPage::operator('without-a-picture');

    OperatorPage::as($tenant, function (): void {
        Product::factory()->create([
            'status' => ProductStatus::Active,
            'title' => ['el' => 'Χωρίς φωτογραφία', 'en' => 'No photograph'],
            'images' => [],
        ]);
    });

    $body = (string) get(HostedRequest::url('/without-a-picture'))->getContent();

    expect(substr_count($body, 'is-empty'))->toBe(2)
        ->and($body)->not->toContain('loading="lazy"');
})->group('fast');
