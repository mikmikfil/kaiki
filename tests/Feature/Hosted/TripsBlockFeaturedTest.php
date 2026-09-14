<?php

declare(strict_types=1);

use App\Enums\HomeBlockType;
use App\Enums\ProductStatus;
use App\Models\HomePageBlock;
use App\Models\Product;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| The row of highlights is optional (HOS-1)
|--------------------------------------------------------------------------
|
| The trips block opens with a scroll-snapped rail of up to six trips and puts
| the rest in a grid beneath it. That is the right shape for a catalogue of
| twenty and an odd one for a catalogue of four: an operator with four trips is
| recommending their whole list to itself, and the visitor scrolls past the same
| four cards twice.
|
| So the rail is a setting. With it off there is nothing held back, which is the
| part worth asserting — a first version that hid the rail but kept its six
| trips out of the grid would take four trips off the page of an operator who
| has five, and the page would still return 200.
|
| The second heading goes with it. "All our trips" over the only grid on the
| page, directly under the block's own title, reads as a section that has lost
| its contents.
|
| The setup is repeated in each test rather than lifted into a helper at the top
| of the file: a file-local `function` in a Pest file is a global one, and
| `Tests\Support\Hosted\OperatorPage` exists because two of those collided and
| took the whole suite down with a fatal error.
|
*/

it('opens with the rail by default, and keeps the rest in the grid below it', function (): void {
    $tenant = OperatorPage::operator('with-a-rail');

    OperatorPage::as($tenant, function (): void {
        // Seven, because the rail takes six: a catalogue that fits in the rail
        // leaves no grid beneath it, and this test is about the shape with
        // both halves in it.
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta'] as $index => $title) {
            Product::factory()->create([
                'status' => ProductStatus::Active,
                'title' => ['el' => $title, 'en' => $title],
                'sort_order' => $index,
            ]);
        }

        HomePageBlock::factory()->ofType(HomeBlockType::Trips)->at(0)->create([
            'heading' => ['el' => 'Εκδρομές', 'en' => 'Trips'],
            'settings' => ['source' => 'all', 'limit' => 0, 'show_featured' => true],
        ]);
    });

    $html = (string) get(HostedRequest::url('/with-a-rail'))->assertOk()->getContent();

    // The markup, not the class name: every page carries the stylesheet these
    // classes are declared in, so `toContain('trips-rail')` is true of a page
    // with no rail on it at all.
    expect($html)->toContain('class="trips trips-rail"')
        ->and($html)->toContain('<h3 class="trips-more">')
        ->and($html)->toContain('Alpha');
})->group('fast');

it('shows every trip in one grid when the rail is off', function (): void {
    $titles = ['Alpha', 'Beta', 'Gamma', 'Delta'];

    $tenant = OperatorPage::operator('no-rail');

    OperatorPage::as($tenant, function () use ($titles): void {
        foreach ($titles as $index => $title) {
            Product::factory()->create([
                'status' => ProductStatus::Active,
                'title' => ['el' => $title, 'en' => $title],
                'sort_order' => $index,
            ]);
        }

        HomePageBlock::factory()->ofType(HomeBlockType::Trips)->at(0)->create([
            'heading' => ['el' => 'Εκδρομές', 'en' => 'Trips'],
            'settings' => ['source' => 'all', 'limit' => 0, 'show_featured' => false],
        ]);
    });

    $html = (string) get(HostedRequest::url('/no-rail'))->assertOk()->getContent();

    expect($html)->not->toContain('class="trips trips-rail"')
        // The second heading goes with the rail it distinguished the grid from.
        ->and($html)->not->toContain('<h3 class="trips-more">');

    // The part that would otherwise be silent: the four trips the rail would
    // have taken are all still on the page.
    foreach ($titles as $title) {
        expect($html)->toContain($title);
    }
})->group('fast');
