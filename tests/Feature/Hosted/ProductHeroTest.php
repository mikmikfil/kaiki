<?php

declare(strict_types=1);

use function Pest\Laravel\get;

use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| The trip page's hero: crumbs, title, standfirst, chips, and the mosaic
|--------------------------------------------------------------------------
|
| The same structure as the WordPress plugin's single-trip template (Mike,
| 2026-09-16). The photographs moved from a gallery at the foot of the page to a
| mosaic under the title, and what these tests guard is that **none of them was
| lost on the way**: the mosaic shows at most five, and every photograph still
| has its lightbox panel.
|
*/

/**
 * @return list<array{path: string, alt: array{el: string, en: string}}>
 */
function heroPhotographs(int $count): array
{
    return array_map(static fn (int $n): array => [
        'path' => "products/hero/photo-{$n}.jpg",
        'alt' => ['el' => "Φωτογραφία {$n}", 'en' => "Photograph {$n}"],
    ], range(1, $count));
}

/** @param array<string, mixed> $attributes */
function heroPage(string $slug, int $photographs, array $attributes = []): string
{
    $tenant = OperatorPage::operator($slug);
    $product = TripPage::create($tenant, ['images' => heroPhotographs($photographs), ...$attributes]);

    return (string) get(TripPage::url($tenant, $product, 'en'))->assertOk()->getContent();
}

it('opens with the way back, the title and the mosaic, then the standfirst and chips beside the booking box', function (): void {
    // Reordered 2026-09-23 (Mike): *«τίτλος μικρότερος και μένει πάνω από τις
    // φωτογραφίες, υπότιτλος και εικονίδια από κάτω»*, then *«το box με το
    // booking να ξεκινάει από πιο πάνω, από το ύψος του υπότιτλου»*.
    //
    // It used to be title → standfirst → chips → mosaic, all inside the hero,
    // with the two columns starting below all of it. Now the hero ends at the
    // photographs and the standfirst opens the left column — which is what puts
    // the booking box level with it.
    $body = heroPage('hero-order', 5, ['default_start_time' => '09:30:00']);

    // Searched from the hero onwards: the summary is also in `<meta
    // name=description>`, which is not what this test is about.
    $hero = (int) strpos($body, 'class="trip-hero"');

    $positions = array_map(
        static fn (string $needle): int|false => strpos($body, $needle, $hero),
        [
            'class="trip-hero"',
            'class="crumbs"',
            __('hosted.product.all_trips', [], 'en'),
            '<h1>Sunset cruise</h1>',
            'class="mosaic mosaic-5"',
            'class="product-body"',
            'Three hours in the Saronic.',
            'class="facts"',
        ],
    );

    expect($positions)->not->toContain(false);

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted)
        // The departure time is a chip of its own.
        ->and($body)->toContain(__('hosted.product.departs', ['time' => '09:30'], 'en'));
})->group('fast');

it('shows five tiles out of seven photographs and keeps all seven in the lightbox', function (): void {
    $body = heroPage('hero-seven', 7);

    $mosaic = substr($body, (int) strpos($body, 'class="mosaic'), (int) strpos($body, 'class="product-body"') - (int) strpos($body, 'class="mosaic'));

    expect(substr_count($mosaic, 'class="shot-open"'))->toBe(5)
        ->and(substr_count($body, 'class="lightbox"'))->toBe(7)
        ->and($body)->toContain('src="/storage/products/hero/photo-7.jpg"')
        ->and($mosaic)->toContain('href="#shot-0">')
        ->and($mosaic)->toContain(__('hosted.product.all_photos', ['count' => 7], 'en'))
        // Needed at every width, so not the phone-only variant.
        ->and($mosaic)->not->toContain('mosaic-all-narrow');
})->group('fast');

it('shows three tiles for four photographs, so no cell of the grid is empty', function (): void {
    $body = heroPage('hero-four', 4);

    expect($body)->toContain('class="mosaic mosaic-3"')
        ->and(substr_count($body, 'class="lightbox"'))->toBe(4)
        // A phone shows three tiles, so the fourth photograph needs the button
        // there — and only there.
        ->and($body)->toContain('mosaic-all mosaic-all-narrow');
})->group('fast');

it('degrades to one tile and no button for a single photograph', function (): void {
    $body = heroPage('hero-one', 1);

    expect($body)->toContain('class="mosaic mosaic-1"')
        ->and($body)->not->toContain('class="mosaic-all')
        ->and(substr_count($body, 'class="lightbox"'))->toBe(1);
})->group('fast');

it('no longer renders the gallery at the foot of the page', function (): void {
    $body = heroPage('hero-no-foot-gallery', 6);

    expect($body)->not->toContain('<ul class="shots shots-masonry">')
        ->and($body)->not->toContain('<h2>' . __('hosted.product.gallery', [], 'en') . '</h2>')
        ->and($body)->not->toContain('class="product-lead"');
})->group('fast');

it('draws no mosaic for a trip without photographs', function (): void {
    $body = heroPage('hero-none', 0, ['images' => []]);

    expect($body)->toContain('class="trip-hero"')
        ->and($body)->not->toContain('class="mosaic')
        ->and($body)->not->toContain('class="lightbox"');
})->group('fast');
