<?php

declare(strict_types=1);

use App\Enums\HomeBlockType;
use App\Models\HomePageBlock;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| An uploaded image on a hosted page is served from that page's own origin
|--------------------------------------------------------------------------
|
| `Storage::url()` builds an absolute URL from `APP_URL`, which is the panel's
| origin. A hosted page is served from `book.kaiki.gr` — or from an operator's
| own custom domain — so every image on it would be cross-origin, and HOS-8's
| policy says `img-src 'self'`.
|
| **The browser blocks it and tells nobody.** A CSP violation is logged in the
| visitor's console and nowhere else, so the failure is a page with holes in it
| that renders perfectly in every test that only asks whether the markup is
| there. Found by looking at a demo page whose hero was a flat colour where a
| photograph should have been (issue 111).
|
| A root-relative path resolves against whichever host is serving the page, and
| satisfies `'self'` on all of them without any being named in a policy.
|
*/

it('references a block image by a root-relative path, not the panel origin', function (): void {
    $tenant = OperatorPage::operator('with-a-picture');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'image_path' => 'home/hero.jpg',
        ]);
    });

    $html = (string) get(HostedRequest::url('/with-a-picture'))->assertOk()->getContent();

    expect($html)->toContain('src="/storage/home/hero.jpg"')
        // The panel's origin, which is what `Storage::url()` would have used.
        ->and($html)->not->toContain((string) config('app.url') . '/storage/home/hero.jpg');
})->group('fast');

it('keeps every image on a hosted page within its own policy', function (): void {
    // The general claim rather than one field: whatever a page ends up
    // rendering, no `<img src>` on it may point at another origin, because
    // `img-src 'self'` is the policy that page is served with.
    $tenant = OperatorPage::operator('all-the-pictures');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'image_path' => 'home/hero.jpg',
        ]);

        HomePageBlock::factory()->ofType(HomeBlockType::Story)->at(1)->create([
            'heading' => ['el' => 'Η ιστορία μας', 'en' => 'Our story'],
            'body' => ['el' => 'Κείμενο', 'en' => 'Words'],
            'image_path' => 'home/story.jpg',
        ]);

        HomePageBlock::factory()->ofType(HomeBlockType::Gallery)->at(2)->create([
            'heading' => ['el' => 'Φωτογραφίες', 'en' => 'Photographs'],
            'images' => [['path' => 'home/one.jpg', 'alt' => ['el' => 'Το καΐκι', 'en' => 'The kaiki']]],
        ]);

        HomePageBlock::factory()->ofType(HomeBlockType::Contact)->at(3)->create([
            'heading' => ['el' => 'Επικοινωνία', 'en' => 'Contact'],
            'image_path' => 'home/contact.jpg',
        ]);
    });

    $html = (string) get(HostedRequest::url('/all-the-pictures'))->assertOk()->getContent();

    preg_match_all('/<img[^>]+src="(?P<src>[^"]+)"/i', $html, $found);

    expect($found['src'])->not->toBeEmpty();

    foreach ($found['src'] as $src) {
        // Relative, or a `data:` URI, which the policy also allows.
        expect(str_starts_with($src, '/') || str_starts_with($src, 'data:'))
            ->toBeTrue("A hosted page pointed an <img> at {$src}, which `img-src 'self'` blocks.");
    }
})->group('fast');
