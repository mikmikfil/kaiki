<?php

declare(strict_types=1);

use App\Enums\HomeBlockType;
use App\Models\HomePageBlock;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| A hero that plays (HOS-1)
|--------------------------------------------------------------------------
|
| An operator may put a short video behind their masthead. Four things have to
| be true of it and none of them is obvious:
|
| - **`muted` and `playsinline`**, or it does not play at all. iOS refuses to
|   autoplay a video with sound and Android opens it full screen — so the
|   attributes are not decoration, they are the difference between a background
|   video and a black rectangle with a play button on it.
| - **The image is still there, as the poster.** It is what a visitor sees
|   before the video loads, on a connection too slow to play it, and whenever
|   the browser declines to autoplay — which a data saver does by default.
| - **`media-src` in the policy.** Without it the video is blocked, the page
|   shows its poster for ever, and the only trace is a line in the visitor's
|   console.
| - **No video for somebody who asked for reduced motion.** A looping video
|   behind text is a vestibular trigger, and `@media (prefers-reduced-motion)`
|   cannot switch an attribute off — which is why the poster carries the case
|   rather than a stylesheet.
|
*/

it('plays a video behind the masthead, with the photograph as its poster', function (): void {
    $tenant = OperatorPage::operator('with-a-video');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'image_path' => 'home/hero.jpg',
            'video_path' => 'home/hero.mp4',
        ]);
    });

    $html = (string) get(HostedRequest::url('/with-a-video'))->assertOk()->getContent();

    expect($html)->toContain('<source src="/storage/home/hero.mp4"')
        // The four attributes, each of which is load-bearing on some phone.
        ->and($html)->toContain('autoplay')
        ->and($html)->toContain('muted')
        ->and($html)->toContain('loop')
        ->and($html)->toContain('playsinline')
        // The poster, which is the whole reason the hero keeps both fields.
        ->and($html)->toContain('poster="/storage/home/hero.jpg"');
})->group('fast');

it('shows the photograph when there is no video', function (): void {
    $tenant = OperatorPage::operator('picture-only');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'image_path' => 'home/hero.jpg',
        ]);
    });

    $html = (string) get(HostedRequest::url('/picture-only'))->assertOk()->getContent();

    expect($html)->toContain('<img class="hero-image"')
        ->and($html)->not->toContain('<video');
})->group('fast');

it('allows the media in the page policy, or the video is blocked in silence', function (): void {
    $tenant = OperatorPage::operator('policy-check');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'video_path' => 'home/hero.mp4',
        ]);
    });

    $response = get(HostedRequest::url('/policy-check'))->assertOk();

    expect((string) $response->headers->get('Content-Security-Policy'))
        ->toContain("media-src 'self'");
})->group('fast');

it('never points the video at another origin', function (): void {
    // Same rule as every other asset on these pages: `media-src 'self'` blocks
    // a cross-origin file, silently, in the visitor's console and nowhere else.
    $tenant = OperatorPage::operator('same-origin');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'video_path' => 'home/hero.mp4',
        ]);
    });

    $html = (string) get(HostedRequest::url('/same-origin'))->assertOk()->getContent();

    preg_match_all('/<source[^>]+src="(?P<src>[^"]+)"/i', $html, $found);

    expect($found['src'])->not->toBeEmpty();

    foreach ($found['src'] as $src) {
        expect(str_starts_with($src, '/'))->toBeTrue("The hero video pointed at {$src}.");
    }
})->group('fast');
