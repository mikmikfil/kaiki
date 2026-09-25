<?php

declare(strict_types=1);

use App\Domain\Hosted\Actions\SaveHomePage;
use App\Enums\HomeBlockType;
use App\Enums\ProductStatus;
use App\Models\HomePageBlock;
use App\Models\Product;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| The home page sections of 16 September
|--------------------------------------------------------------------------
|
| «Αριθμοί», «Πώς λειτουργεί», «Γιατί εμάς», «Κριτικές» and «Κάλεσμα», brought
| over from the design of the operator's WordPress site — and the hero's two
| buttons and trust badges, the small line above each heading, and a label on
| a trip card.
|
| Two groups of claim. **What is stored** is exactly what the whitelist in
| `BlockItems` allows, whoever calls the Action. **What is rendered** is the
| operator's words, in the locale being read, with every link built from a
| target this platform names — never an address somebody typed.
|
*/

/**
 * Save one page through the Action, inside a fresh operator.
 *
 * @param  list<array<string, mixed>>  $blocks
 */
function sectionsPage(string $slug, array $blocks): string
{
    $tenant = OperatorPage::operator($slug);

    OperatorPage::as($tenant, static function () use ($blocks): void {
        app(SaveHomePage::class)($blocks);
    });

    return (string) get(HostedRequest::url("/{$slug}?lang=el"))->assertOk()->getContent();
}

/** @return array{el: string, en: string} */
function both(string $el, string $en): array
{
    return ['el' => $el, 'en' => $en];
}

// --- what is stored ----------------------------------------------------------

it('caps each list at its type maximum and drops entries that say nothing', function (): void {
    OperatorPage::as(OperatorPage::operator('caps'), static function (): void {
        $figure = static fn (string $value): array => ['value' => both($value, $value), 'label' => both('λέξη', 'word')];

        app(SaveHomePage::class)([
            OperatorPage::input(HomeBlockType::Stats, [
                'items' => [$figure('1'), ['value' => both('', ''), 'label' => both('κενό', 'empty')], $figure('2'), $figure('3'), $figure('4'), $figure('5')],
            ]),
        ]);

        $values = array_map(static fn (array $entry): string => $entry['value']['el'], HomePageBlock::query()->sole()->entries());

        // The blank figure is gone, and the fifth is past the card's four.
        expect($values)->toBe(['1', '2', '3', '4']);
    });
})->group('fast');

it('keeps an icon only from the fixed list and a rating only from one to five', function (): void {
    OperatorPage::as(OperatorPage::operator('whitelist'), static function (): void {
        app(SaveHomePage::class)([
            OperatorPage::input(HomeBlockType::Features, [
                'items' => [['icon' => '<svg onload=alert(1)>', 'title' => both('Λόγος', 'Reason')]],
            ]),
            OperatorPage::input(HomeBlockType::Testimonials, [
                'items' => [
                    ['quote' => both('Υπέροχα', 'Lovely'), 'name' => 'Ελένη Π.', 'rating' => 9, 'avatar' => '../../.env'],
                    ['quote' => both('Καλά', 'Fine'), 'rating' => -3],
                ],
            ]),
        ]);

        $blocks = HomePageBlock::query()->orderBy('sort_order')->get();
        $reviews = $blocks[1]->entries();

        expect($blocks[0]->entries()[0]['icon'])->toBe('check')
            ->and($reviews[0]['rating'])->toBe(5)
            ->and($reviews[0]['avatar'])->toBeNull()
            ->and($reviews[1]['rating'])->toBe(1);
    });
})->group('fast');

it('refuses a button that could leave the operator site', function (): void {
    OperatorPage::as(OperatorPage::operator('no-escape'), static function (): void {
        app(SaveHomePage::class)([
            OperatorPage::input(HomeBlockType::Cta, [
                'buttons' => [
                    ['label' => both('Έξω', 'Out'), 'target' => 'page', 'path' => '//evil.example/phish'],
                    ['label' => both('Σχήμα', 'Scheme'), 'target' => 'page', 'path' => 'javascript:alert(1)'],
                    ['label' => both('Άγνωστο', 'Unknown'), 'target' => 'https://evil.example'],
                    ['label' => both('Όροι', 'Terms'), 'target' => 'page', 'path' => '/legal'],
                ],
            ]),
        ]);

        $buttons = HomePageBlock::query()->sole()->buttonEntries();

        expect($buttons)->toHaveCount(1)
            ->and($buttons[0]['target'])->toBe('page')
            ->and($buttons[0]['path'])->toBe('legal');
    });
})->group('fast');

it('retires the hero old single button once the editor sends buttons', function (): void {
    OperatorPage::as(OperatorPage::operator('hero-buttons'), static function (): void {
        // An editor save with every button removed: an empty list, not absent.
        app(SaveHomePage::class)([OperatorPage::input(HomeBlockType::Hero, ['buttons' => []])]);

        expect(HomePageBlock::query()->sole()->setting('cta'))->toBe('none');

        // A seeder or an import that knows nothing of buttons keeps the old one.
        app(SaveHomePage::class)([OperatorPage::input(HomeBlockType::Hero)]);

        expect(HomePageBlock::query()->sole()->setting('cta'))->toBe('trips');
    });
})->group('fast');

// --- what is rendered --------------------------------------------------------

it('renders the figures card with the operator words', function (): void {
    $body = sectionsPage('figures', [
        OperatorPage::input(HomeBlockType::Hero),
        OperatorPage::input(HomeBlockType::Stats, [
            'eyebrow' => both('Σε αριθμούς', 'In numbers'),
            'heading' => both('Τριάντα χρόνια στη θάλασσα', 'Thirty years at sea'),
            'items' => [
                ['icon' => 'anchor', 'value' => both('30+', '30+'), 'label' => both('χρόνια στη θάλασσα', 'years at sea')],
                ['icon' => 'not-an-icon', 'value' => both('4', '4'), 'label' => both('σκάφη', 'boats')],
            ],
        ]),
    ]);

    // Its own section, not a card over the masthead.
    expect($body)->toContain('class="block band stats-block"')
        ->and($body)->toContain('<p class="eyebrow-line">Σε αριθμούς</p>')
        ->and($body)->toContain('<h2>Τριάντα χρόνια στη θάλασσα</h2>')
        ->and($body)->toContain('<strong>30+</strong>')
        ->and($body)->toContain('χρόνια στη θάλασσα')
        // No icons since the heading-beside-figures layout (2026-09-16).
        ->and($body)->not->toContain('class="stat-icon"');
})->group('fast');

it('renders the steps in order, the reasons with their icons, and a dark band when asked', function (): void {
    $body = sectionsPage('steps-and-reasons', [
        OperatorPage::input(HomeBlockType::Steps, [
            'eyebrow' => both('Πώς λειτουργεί', 'How it works'),
            'items' => [
                ['title' => both('Διαλέξτε εκδρομή', 'Choose'), 'text' => both('Κείμενο ένα', 'One')],
                ['title' => both('Πληρώστε online', 'Pay'), 'text' => both('Κείμενο δύο', 'Two')],
            ],
        ]),
        OperatorPage::input(HomeBlockType::Features, [
            'settings' => ['dark' => true],
            'items' => [['icon' => 'anchor', 'title' => both('Έμπειρο πλήρωμα', 'Crew'), 'text' => both('Ξέρουν κάθε όρμο.', 'Coves.')]],
        ]),
    ]);

    expect($body)->toContain('<p class="eyebrow-line">Πώς λειτουργεί</p>')
        ->and($body)->toContain('<ol class="route route-2" data-animate>')
        ->and(strpos($body, 'Διαλέξτε εκδρομή'))->toBeLessThan(strpos($body, 'Πληρώστε online'))
        ->and($body)->toContain('band-dark')
        ->and($body)->toContain('Έμπειρο πλήρωμα');
})->group('fast');

it('does not fall back to platform copy for steps the operator deleted', function (): void {
    $body = sectionsPage('no-steps', [OperatorPage::input(HomeBlockType::Steps, ['heading' => null])]);

    expect($body)->not->toContain('class="block steps-block steps-route')
        ->and($body)->not->toContain(__('hosted.blocks.steps.defaults.0.title', [], 'el'));
})->group('fast');

it('says a review rating in words and draws the stars for the eye only', function (): void {
    $body = sectionsPage('reviews', [
        OperatorPage::input(HomeBlockType::Testimonials, [
            'items' => [['quote' => both('Η καλύτερη μέρα.', 'The best day.'), 'name' => 'Ελένη Π.', 'trip' => both('Τρία νησιά', 'Three islands'), 'rating' => 4]],
        ]),
    ]);

    expect($body)->toContain('<span aria-hidden="true">★★★★☆</span>')
        ->and($body)->toContain(trans_choice('hosted.blocks.testimonials.rating', 4, ['count' => 4], 'el'))
        ->and($body)->toContain('<blockquote>Η καλύτερη μέρα.</blockquote>')
        // No photograph, so the first letter.
        ->and($body)->toContain('<span class="avatar" aria-hidden="true">Ε</span>');
})->group('fast');

it('builds every call-to-action link from a named target on the operator site', function (): void {
    $tenant = OperatorPage::operator('band');

    OperatorPage::as($tenant, static function (): void {
        $trip = Product::factory()->create(['slug' => 'sunset-trip', 'status' => ProductStatus::Active]);

        app(SaveHomePage::class)([
            OperatorPage::input(HomeBlockType::Cta, [
                'image_path' => 'home/band.jpg',
                'image_alt' => both('Το σκάφος', 'The boat'),
                'buttons' => [
                    ['label' => both('Η εκδρομή', 'The trip'), 'target' => 'trip', 'product_id' => $trip->getKey()],
                    ['label' => both('Οι όροι', 'The terms'), 'target' => 'page', 'path' => 'legal'],
                ],
            ]),
        ]);
    });

    $body = (string) get(HostedRequest::url('/band?lang=el'))->assertOk()->getContent();

    expect($body)->toContain('/band/sunset-trip')
        ->and($body)->toContain('/band/legal"')
        ->and($body)->toContain('>Η εκδρομή</a>')
        ->and($body)->toContain('alt="Το σκάφος"')
        ->and($body)->not->toContain('evil.example');
})->group('fast');

it('drops a button to a trip that is no longer on sale', function (): void {
    $tenant = OperatorPage::operator('withdrawn');

    OperatorPage::as($tenant, static function (): void {
        $trip = Product::factory()->create(['slug' => 'gone-trip', 'status' => ProductStatus::Archived]);

        app(SaveHomePage::class)([
            OperatorPage::input(HomeBlockType::Cta, [
                'buttons' => [['label' => both('Η εκδρομή', 'The trip'), 'target' => 'trip', 'product_id' => $trip->getKey()]],
            ]),
        ]);
    });

    expect((string) get(HostedRequest::url('/withdrawn?lang=el'))->getContent())->not->toContain('gone-trip');
})->group('fast');

it('renders the hero eyebrow, the operator buttons and the trust badges', function (): void {
    $body = sectionsPage('masthead', [
        OperatorPage::input(HomeBlockType::Hero, [
            'eyebrow' => both('Εκδρομές από τον Πειραιά', 'Trips from Piraeus'),
            'buttons' => [
                ['label' => both('Δείτε τις εκδρομές', 'See our trips'), 'target' => 'trips'],
                ['label' => both('Ιδιωτική ναύλωση', 'Private charter'), 'target' => 'contact'],
            ],
            'items' => [['icon' => 'sun', 'text' => both('Εγγύηση καιρού', 'Weather guarantee')]],
        ]),
    ]);

    expect($body)->toContain('<p class="eyebrow-line">Εκδρομές από τον Πειραιά</p>')
        ->and($body)->toContain('>Ιδιωτική ναύλωση</a>')
        ->and($body)->toContain('/masthead/contact')
        ->and($body)->toContain('class="hero-badges"')
        ->and($body)->toContain('Εγγύηση καιρού');
})->group('fast');

it('leaves a hidden new section off the page without losing it', function (): void {
    $tenant = OperatorPage::operator('hidden-reviews');

    OperatorPage::as($tenant, static function (): void {
        app(SaveHomePage::class)([
            OperatorPage::input(HomeBlockType::Testimonials, [
                'is_visible' => false,
                'items' => [['quote' => both('Κρυφή κριτική', 'Hidden review'), 'name' => 'Νίκος']],
            ]),
        ]);
    });

    get(HostedRequest::url('/hidden-reviews?lang=el'))->assertOk()->assertDontSee('Κρυφή κριτική', escape: false);

    OperatorPage::as($tenant, static function (): void {
        expect(HomePageBlock::query()->sole()->entries())->toHaveCount(1);
    });
})->group('fast');

it('puts the operator label on a trip card, and nothing when there is none', function (): void {
    $tenant = OperatorPage::operator('labels');

    OperatorPage::as($tenant, static function (): void {
        Product::factory()->create(['status' => ProductStatus::Active, 'title' => both('Πρωινό', 'Morning'), 'badge' => both('Δημοφιλές', 'Popular')]);
        Product::factory()->create(['status' => ProductStatus::Active, 'title' => both('Απόγευμα', 'Afternoon')]);
    });

    $greek = (string) get(HostedRequest::url('/labels?lang=el'))->getContent();
    $english = (string) get(HostedRequest::url('/labels?lang=en'))->getContent();

    expect(substr_count($greek, '<p class="trip-badge">'))->toBe(1)
        ->and($greek)->toContain('<p class="trip-badge">Δημοφιλές</p>')
        ->and($english)->toContain('<p class="trip-badge">Popular</p>');
})->group('fast');

it('never names the case-changing property in the new styles', function (): void {
    $body = sectionsPage('no-shouting', [OperatorPage::input(HomeBlockType::Features)]);

    expect($body)->not->toContain('text-transform')->and($body)->not->toContain('uppercase');
})->group('fast');
