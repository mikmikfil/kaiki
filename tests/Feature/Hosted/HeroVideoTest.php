<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\HostedPageCsp;
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

it('frames a YouTube link behind the masthead, with the photograph underneath it', function (): void {
    $tenant = OperatorPage::operator('with-a-link');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'image_path' => 'home/hero.jpg',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);
    });

    $html = (string) get(HostedRequest::url('/with-a-link'))->assertOk()->getContent();

    // The privacy-enhanced host, muted and looping — and never the operator's
    // own string, which is where an attribute could be broken out of.
    expect($html)->toContain('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and($html)->toContain('mute=1')
        // An iframe has no `poster`, so the photograph is a real element
        // beneath it: what a visitor sees while the player loads, where the
        // provider is unreachable, and when they asked for less motion.
        ->and($html)->toContain('<img class="hero-image"');
})->group('fast');

it('names the player in the page policy, or the frame is blocked in silence', function (): void {
    $tenant = OperatorPage::operator('frame-policy');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'video_url' => 'https://vimeo.com/347119375',
        ]);
    });

    $csp = (string) get(HostedRequest::url('/frame-policy'))
        ->assertOk()
        ->headers->get('Content-Security-Policy');

    expect($csp)->toContain('https://player.vimeo.com')
        // The map is still there: this widens `frame-src`, it does not replace it.
        ->and($csp)->toContain(HostedPageCsp::MAPS_ORIGIN)
        // And only the provider this operator uses.
        ->and($csp)->not->toContain('youtube');
})->group('fast');

it('leaves the policy alone for an operator who has pasted no link', function (): void {
    $tenant = OperatorPage::operator('no-link-at-all');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'image_path' => 'home/hero.jpg',
        ]);
    });

    $csp = (string) get(HostedRequest::url('/no-link-at-all'))
        ->assertOk()
        ->headers->get('Content-Security-Policy');

    // The same argument as the font origins: an operator who has not asked for
    // a third party has no reason to carry one in their policy.
    expect($csp)->toContain('frame-src ' . HostedPageCsp::MAPS_ORIGIN)
        ->and($csp)->not->toContain('vimeo')
        ->and($csp)->not->toContain('youtube');
})->group('fast');

it('plays the uploaded file when an operator has both, and says so nowhere else', function (): void {
    $tenant = OperatorPage::operator('both-of-them');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'video_path' => 'home/hero.mp4',
            'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
    });

    $html = (string) get(HostedRequest::url('/both-of-them'))->assertOk()->getContent();

    // The file is on our own disk, inside our own policy, under a size limit
    // the form states. An operator with both has a leftover, not a preference.
    expect($html)->toContain('<source src="/storage/home/hero.mp4"')
        ->and($html)->not->toContain('youtube-nocookie');
})->group('fast');

it('falls back to the photograph for a link it cannot frame', function (): void {
    $tenant = OperatorPage::operator('a-bad-link');

    OperatorPage::as($tenant, function (): void {
        // Saved before the rule existed, or written by an import. The editor
        // refuses this one; the page still has to render.
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->at(0)->create([
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'image_path' => 'home/hero.jpg',
            'video_url' => 'https://www.facebook.com/watch?v=1234567890',
        ]);
    });

    $html = (string) get(HostedRequest::url('/a-bad-link'))->assertOk()->getContent();

    expect($html)->toContain('<img class="hero-image"')
        ->and($html)->not->toContain('<iframe')
        ->and($html)->not->toContain('facebook.com');
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
