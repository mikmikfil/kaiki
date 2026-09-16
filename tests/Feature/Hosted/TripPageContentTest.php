<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Tenancy;

use function Pest\Laravel\get;

use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| The trip page's optional content (2026-09-16)
|--------------------------------------------------------------------------
|
| «Τι θα ζήσετε», the programme, what is and is not included, what to bring.
| Every one optional, and the claim these tests make is the one a screenshot
| cannot: an empty section is **absent from the HTML** — its heading included —
| rather than hidden, and a list of lines that are only spaces counts as empty.
|
*/

/**
 * A fully furnished trip with the content columns set as given.
 *
 * `TripPage::create()` fills includes, excludes, what to bring and an itinerary;
 * a column passed here as null is written as a real null afterwards, because a
 * null through the model is stored as `{"el": null}`.
 *
 * @param  array<string, mixed>  $content
 */
function contentTrip(string $slug, array $content): string
{
    $tenant = OperatorPage::operator($slug);
    $product = TripPage::create($tenant, array_filter($content, static fn (mixed $value): bool => $value !== null));

    $nulls = array_keys(array_filter($content, static fn (mixed $value): bool => $value === null));

    if ($nulls !== []) {
        Tenancy::forTenant($tenant, static function () use ($product, $nulls): void {
            Product::query()->whereKey($product->getKey())->toBase()->update(array_fill_keys($nulls, null));
        });
    }

    return (string) get(TripPage::url($tenant, $product, 'el'))->assertOk()->getContent();
}

it('renders every section the operator filled in, in the page language', function (): void {
    $body = contentTrip('content-full', [
        'highlights' => ['el' => ['Τρεις στάσεις για μπάνιο'], 'en' => ['Three swimming stops']],
        'itinerary_stops' => [
            'el' => [
                ['key' => 's1', 'time' => '09:00', 'name' => 'Επιβίβαση στη Μαρίνα Ζέας'],
                ['key' => 's2', 'name' => 'Μπάνιο όπου θέλετε', 'description' => 'Όσο κρατάει.'],
            ],
            'en' => [
                ['key' => 's1', 'time' => '09:00', 'name' => 'Boarding at Zea Marina'],
                ['key' => 's2', 'name' => 'A swim wherever you like'],
            ],
        ],
    ]);

    expect($body)->toContain('<h2>' . __('hosted.product.highlights', [], 'el') . '</h2>')
        ->and($body)->toContain('Τρεις στάσεις για μπάνιο')
        ->and($body)->toContain('<h2>' . __('hosted.product.itinerary', [], 'el') . '</h2>')
        ->and($body)->toContain('<span class="trip-time">09:00</span>')
        ->and($body)->toContain('Επιβίβαση στη Μαρίνα Ζέας')
        ->and($body)->toContain('Όσο κρατάει.')
        // TripPage's own lists.
        ->and($body)->toContain('<h2>' . __('hosted.product.includes', [], 'el') . '</h2>')
        ->and($body)->toContain('Ποτήρι κρασί')
        ->and($body)->toContain('<h2>' . __('hosted.product.excludes', [], 'el') . '</h2>')
        ->and($body)->toContain('<h2>' . __('hosted.product.what_to_bring', [], 'el') . '</h2>')
        // One time only: the second stop has none, and prints no empty badge.
        ->and(substr_count($body, 'class="trip-time"'))->toBe(1);
})->group('fast');

it('leaves every empty section out of the page, heading and all', function (): void {
    $body = contentTrip('content-none', [
        'highlights' => null,
        'itinerary_stops' => null,
        'includes' => null,
        'excludes' => null,
        'what_to_bring' => null,
    ]);

    foreach (['highlights', 'itinerary', 'includes', 'excludes', 'what_to_bring'] as $key) {
        expect($body)->not->toContain('<h2>' . __("hosted.product.{$key}", [], 'el') . '</h2>');
    }

    // Markup, not the stylesheet every page carries.
    expect($body)->not->toContain('<ul class="trip-list')
        ->and($body)->not->toContain('<ol class="itinerary trip-timeline"')
        ->and($body)->not->toContain('class="section trip-content');
})->group('fast');

it('treats lines of only spaces, and an empty list, as nothing to show', function (): void {
    $body = contentTrip('content-blank', [
        'highlights' => ['el' => ['   ', ''], 'en' => ['  ']],
        'includes' => ['el' => [], 'en' => []],
        'excludes' => null,
        'what_to_bring' => ['el' => ['Αντηλιακό'], 'en' => ['Sunscreen']],
    ]);

    expect($body)->not->toContain('<h2>' . __('hosted.product.highlights', [], 'el') . '</h2>')
        ->and($body)->not->toContain('<h2>' . __('hosted.product.includes', [], 'el') . '</h2>')
        ->and($body)->not->toContain('<h2>' . __('hosted.product.excludes', [], 'el') . '</h2>')
        // The one that has a line still shows, on its own.
        ->and($body)->toContain('<h2>' . __('hosted.product.what_to_bring', [], 'el') . '</h2>')
        ->and($body)->toContain('Αντηλιακό');
})->group('fast');

it('puts no case-changing property and no script into the content sections', function (): void {
    $body = contentTrip('content-rules', [
        'highlights' => ['el' => ['<script>alert(1)</script>'], 'en' => ['x']],
    ]);

    expect($body)->not->toContain('<script>alert(1)</script>')
        ->and($body)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($body)->not->toContain('text-transform')
        ->and($body)->not->toContain('uppercase');
})->group('fast');
