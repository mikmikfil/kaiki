<?php

declare(strict_types=1);

use App\Enums\HomeBlockType;
use App\Models\HomePageBlock;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| The "about us" block, and the control that did nothing
|--------------------------------------------------------------------------
|
| `image_side` shipped in #102 with a form field, a lang key, a stored setting
| and no effect whatsoever. The `<img>` was first in the DOM, so the default
| ("right") left it on the left, and `side-left`'s `order: -1` moved it to where
| it already was. Both settings rendered the same page, and no test looked.
|
| The reason it survived two milestones is that every other assertion about this
| block passes either way: the heading is there, the prose is there, the image
| is there, the class is on the section. What nobody checked was the one thing
| the setting exists to change — which of the two comes first.
|
| So these assert **order**, not presence, and they do it against the markup
| rather than the CSS: the layout is a grid whose default order is DOM order,
| and DOM order is the thing a template can get wrong.
|
*/

/** The rendered story section, so an assertion can talk about what is inside it. */
function storySection(string $html): string
{
    expect($html)->toContain('class="block story');

    $start = strpos($html, '<section class="block story');
    expect($start)->not->toBeFalse();

    $end = strpos($html, '</section>', (int) $start);
    expect($end)->not->toBeFalse();

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/**
 * Where the copy and the image sit relative to each other.
 *
 * @return array{copy: int|false, image: int|false}
 */
function storyOrder(string $section): array
{
    return [
        'copy' => strpos($section, 'story-copy'),
        'image' => strpos($section, 'story-image'),
    ];
}

function storyOperator(string $slug, string $side): string
{
    $tenant = OperatorPage::operator($slug);

    OperatorPage::as($tenant, function () use ($side): void {
        HomePageBlock::create([
            'type' => HomeBlockType::Story,
            'sort_order' => 0,
            'is_visible' => true,
            'heading' => ['el' => 'Ποιοι είμαστε', 'en' => 'Who we are'],
            'body' => ['el' => 'Δύο αδέρφια κι ένα καΐκι.', 'en' => 'Two brothers and one boat.'],
            'image_path' => 'branding/1/story.jpg',
            'settings' => ['image_side' => $side],
        ]);
    });

    return get(HostedRequest::url('/' . $slug))->assertOk()->getContent();
}

it('puts the image after the copy by default, which is the image on the right', function (): void {
    $section = storySection(storyOperator('story-right', 'right'));

    ['copy' => $copy, 'image' => $image] = storyOrder($section);

    // The heading reaches a screen reader and a crawler before its own
    // illustration, and on a narrow screen the column reads heading, prose,
    // photograph — the order somebody would write it in.
    expect($copy)->toBeLessThan($image)
        ->and($section)->toContain('side-right')
        ->and($section)->toContain('has-image');
});

it('marks the other side with a class the stylesheet can move', function (): void {
    $section = storySection(storyOperator('story-left', 'left'));

    // The DOM order does not change — the reading order should not depend on
    // which side the photograph is on. `side-left` is what the grid acts on,
    // and an inline `style` would be dropped by HOS-8's policy.
    ['copy' => $copy, 'image' => $image] = storyOrder($section);

    expect($copy)->toBeLessThan($image)
        ->and($section)->toContain('side-left');
});

it('renders a story with no photograph as prose alone', function (): void {
    $tenant = OperatorPage::operator('story-plain');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::create([
            'type' => HomeBlockType::Story,
            'sort_order' => 0,
            'is_visible' => true,
            'heading' => ['el' => 'Ποιοι είμαστε', 'en' => 'Who we are'],
            'body' => ['el' => 'Δύο αδέρφια κι ένα καΐκι.', 'en' => 'Two brothers and one boat.'],
            'settings' => ['image_side' => 'right'],
        ]);
    });

    $section = storySection(get(HostedRequest::url('/story-plain'))->assertOk()->getContent());

    // No `has-image`, so the grid stays one column rather than leaving half the
    // row empty next to a paragraph.
    expect($section)->not->toContain('has-image')
        ->and($section)->not->toContain('story-image')
        ->and($section)->toContain('Ποιοι είμαστε');
});
