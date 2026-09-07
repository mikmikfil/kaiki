<?php

declare(strict_types=1);

use App\Enums\HomeBlockType;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Enums\TenantStatus;
use App\Models\Faq;
use App\Models\HomePageBlock;
use App\Models\Product;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| #102: the operator writes their own home page, and cannot write markup
|--------------------------------------------------------------------------
|
| Two separable claims, and the second is the one worth the file.
|
| **It renders what the operator wrote** — five types, in the operator's order,
| with hidden blocks absent and both locales served. Ordinary, and asserted
| ordinarily.
|
| **It renders nothing the operator did not write.** HOS-8 spent an issue
| removing `unsafe-inline` from the policy, and an editor that let an operator
| paste a `<script>` into the page body would hand back the surface that issue
| closed — not because the script would run under the CSP, but because the same
| text becomes a `meta description`, a WordPress SEO post (WPP-6) and an email,
| and only one of those three has a CSP. So the escaping is asserted against the
| page **and** against the claim #101 made: that a hosted page contains no
| `<script` at all.
|
*/

it('renders the default page for an operator who has never opened the editor', function (): void {
    $tenant = OperatorPage::operator('untouched');

    $response = get(HostedRequest::url('/untouched'));

    // The page #101 served, expressed in blocks. Not an empty state and not a
    // platform sentence — the operator's own name, because a default page that
    // said "Welcome to our boat tours" would ship somebody else's copy to every
    // operator who never edits it.
    $response->assertOk()
        // `e()` rather than the raw name: a faker company name containing an
        // apostrophe — O'Conner Group — is escaped by Blade to `&#039;`, and an
        // unescaped assertion then fails on roughly one run in twenty for a
        // reason that has nothing to do with the page. Found in #104.
        ->assertSee(e($tenant->name), escape: false)
        ->assertSee(__('hosted.index.trips', [], 'el'), escape: false)
        ->assertSee(__('hosted.blocks.contact.heading', [], 'el'), escape: false);
})->group('fast');

it('renders each of the block types', function (): void {
    $tenant = OperatorPage::operator('all-five');

    OperatorPage::as($tenant, function (): void {
        $order = 0;

        // #103's FAQ block is a mount: with no published entries it renders
        // nothing, heading included, for the same reason the empty gallery
        // does. So it gets a question, exactly as the gallery gets a
        // photograph.
        Faq::factory()->asking('Ερώτηση;', 'A question?')->create();

        foreach (HomeBlockType::cases() as $type) {
            HomePageBlock::factory()->ofType($type)->at($order++)->create([
                'heading' => ['el' => "Ενότητα {$type->value}", 'en' => "Section {$type->value}"],
                // A gallery with no photographs renders nothing at all — the
                // heading included, because a lone heading over empty space is
                // worse than an absent section. So this one gets a photograph.
                'images' => $type === HomeBlockType::Gallery
                    ? [['path' => 'home/one.jpg', 'alt' => ['el' => 'Το καΐκι', 'en' => 'The kaiki']]]
                    : null,
            ]);
        }
    });

    $response = get(HostedRequest::url('/all-five?lang=el'));

    $response->assertOk();

    foreach (HomeBlockType::cases() as $type) {
        $response->assertSee("Ενότητα {$type->value}", escape: false);
    }
})->group('fast');

it('renders blocks in the operator order rather than the order they were created', function (): void {
    $tenant = OperatorPage::operator('ordered');

    OperatorPage::as($tenant, function (): void {
        // Created last, shown first. A page whose order came from `id` would
        // pass every single-block test in this file.
        HomePageBlock::factory()->at(2)->create(['heading' => ['el' => 'Τρίτο', 'en' => 'Third']]);
        HomePageBlock::factory()->at(0)->create(['heading' => ['el' => 'Πρώτο', 'en' => 'First']]);
        HomePageBlock::factory()->at(1)->create(['heading' => ['el' => 'Δεύτερο', 'en' => 'Second']]);
    });

    $body = (string) get(HostedRequest::url('/ordered?lang=el'))->getContent();

    expect(mb_strpos($body, 'Πρώτο'))->toBeLessThan(mb_strpos($body, 'Δεύτερο'))
        ->and(mb_strpos($body, 'Δεύτερο'))->toBeLessThan(mb_strpos($body, 'Τρίτο'));
})->group('fast');

it('leaves a hidden block out without losing it', function (): void {
    $tenant = OperatorPage::operator('hidden-block');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->hidden()->create(['heading' => ['el' => 'Χειμώνας', 'en' => 'Winter']]);
    });

    get(HostedRequest::url('/hidden-block'))->assertOk()->assertDontSee('Χειμώνας', escape: false);

    // Hidden, not deleted — an operator taking the gallery down for the winter
    // should not have to retype it in the spring.
    OperatorPage::as($tenant, function (): void {
        expect(HomePageBlock::query()->count())->toBe(1);
    });
})->group('fast');

it('escapes an operator who pastes markup, and the page still has no script tag', function (): void {
    $tenant = OperatorPage::operator('paste');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()
            ->ofType(HomeBlockType::Story)
            ->saying(
                '<script>alert(1)</script> και <b>έντονα</b>',
                '<script>alert(1)</script> and <b>bold</b>',
            )
            ->create();
    });

    $body = (string) get(HostedRequest::url('/paste?lang=el'))->assertOk()->getContent();

    // The characters survive, as characters. The elements do not.
    expect($body)->toContain('&lt;script&gt;')
        ->and($body)->toContain('&lt;b&gt;')
        ->and($body)->not->toContain('<b>')
        // #101's HOS-4 claim, restated here because #102 is the issue that
        // could have broken it: an editable page is the obvious place for a
        // rich-text field, and a rich-text field is how `<script` arrives.
        ->and($body)->not->toContain('<script');
})->group('fast');

it('turns a blank line into a paragraph and a single newline into a break', function (): void {
    $tenant = OperatorPage::operator('paragraphs');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()
            ->ofType(HomeBlockType::Story)
            ->saying("Πρώτη γραμμή\nδεύτερη γραμμή\n\nΔεύτερη παράγραφος", "First\nsecond\n\nSecond paragraph")
            ->create();
    });

    $body = (string) get(HostedRequest::url('/paragraphs?lang=el'))->assertOk()->getContent();

    // `nl2br` on the whole string would give one paragraph and three breaks,
    // which is the single most common complaint about text areas rendered this
    // way — the operator typed paragraphs and got none.
    expect($body)->toContain('<p>Πρώτη γραμμή<br>δεύτερη γραμμή</p>')
        ->and($body)->toContain('<p>Δεύτερη παράγραφος</p>');
})->group('fast');

it('serves the operator prose in both locales', function (): void {
    $tenant = OperatorPage::operator('bilingual');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Story)->saying('Στα ελληνικά', 'In English')->create();
    });

    get(HostedRequest::url('/bilingual?lang=el'))->assertSee('Στα ελληνικά', escape: false)->assertDontSee('In English');
    get(HostedRequest::url('/bilingual?lang=en'))->assertSee('In English', escape: false)->assertDontSee('Στα ελληνικά');
})->group('fast');

it('shows one operator nothing of another operator page', function (): void {
    $first = OperatorPage::operator('first-op');
    $second = OperatorPage::operator('second-op');

    OperatorPage::as($first, function (): void {
        HomePageBlock::factory()->create(['heading' => ['el' => 'Μυστικό Α', 'en' => 'Secret A']]);
    });

    OperatorPage::as($second, function (): void {
        HomePageBlock::factory()->create(['heading' => ['el' => 'Μυστικό Β', 'en' => 'Secret B']]);
    });

    get(HostedRequest::url('/first-op?lang=el'))->assertSee('Μυστικό Α', escape: false)->assertDontSee('Μυστικό Β', escape: false);
    get(HostedRequest::url('/second-op?lang=el'))->assertSee('Μυστικό Β', escape: false)->assertDontSee('Μυστικό Α', escape: false);
})->group('fast');

it('honours the trips block filters', function (): void {
    $tenant = OperatorPage::operator('filtered');

    OperatorPage::as($tenant, function (): void {
        Product::factory()->create([
            'status' => ProductStatus::Active,
            'category' => ProductCategory::Sunset,
            'is_featured' => true,
            'title' => ['el' => 'Ηλιοβασίλεμα', 'en' => 'Sunset'],
        ]);

        Product::factory()->create([
            'status' => ProductStatus::Active,
            'category' => ProductCategory::SharedFullDay,
            'is_featured' => false,
            'title' => ['el' => 'Ολοήμερη', 'en' => 'Full day'],
        ]);

        HomePageBlock::factory()
            ->ofType(HomeBlockType::Trips)
            ->withSettings(['source' => 'featured'])
            ->create();
    });

    get(HostedRequest::url('/filtered?lang=el'))
        ->assertOk()
        ->assertSee('Ηλιοβασίλεμα', escape: false)
        ->assertDontSee('Ολοήμερη', escape: false);
})->group('fast');

it('falls back to every trip when a category block names no category', function (): void {
    $tenant = OperatorPage::operator('reconciled');

    OperatorPage::as($tenant, function (): void {
        Product::factory()->create([
            'status' => ProductStatus::Active,
            'title' => ['el' => 'Μια εκδρομή', 'en' => 'A trip'],
        ]);

        // Individually valid, jointly nonsense. Reconciled rather than refused,
        // because this runs on **read** — a stored row that became invalid when
        // a category was deleted must still render a page a guest is looking at.
        HomePageBlock::factory()
            ->ofType(HomeBlockType::Trips)
            ->withSettings(['source' => 'category', 'category' => null])
            ->create();
    });

    get(HostedRequest::url('/reconciled?lang=el'))->assertOk()->assertSee('Μια εκδρομή', escape: false);
})->group('fast');

it('takes the meta description from the operator own words once they have written any', function (): void {
    $tenant = OperatorPage::operator('described');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()
            ->ofType(HomeBlockType::Story)
            ->saying('Ναυλώσεις στον Σαρωνικό από το 1998.', 'Charters in the Saronic gulf since 1998.')
            ->create();
    });

    get(HostedRequest::url('/described?lang=el'))
        ->assertOk()
        ->assertSee('Ναυλώσεις στον Σαρωνικό από το 1998.', escape: false)
        // The generic line is the fallback, not the default: a page that has
        // been written should stop advertising itself in platform copy.
        ->assertDontSee(__('hosted.index.meta_description', ['operator' => $tenant->name], 'el'), escape: false);
})->group('fast');

it('describes a gallery image in the locale being read', function (): void {
    $tenant = OperatorPage::operator('gallery');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Gallery)->create([
            'images' => [
                ['path' => 'home/kaiki.jpg', 'alt' => ['el' => 'Το καΐκι στο λιμάνι', 'en' => 'The kaiki in harbour']],
            ],
        ]);
    });

    get(HostedRequest::url('/gallery?lang=el'))->assertOk()->assertSee('Το καΐκι στο λιμάνι', escape: false);
    get(HostedRequest::url('/gallery?lang=en'))->assertOk()->assertSee('The kaiki in harbour', escape: false);
})->group('fast');

it('uppercases nothing an operator wrote, in either locale', function (): void {
    $tenant = OperatorPage::operator('no-caps-blocks');

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Hero)->create();
        HomePageBlock::factory()->ofType(HomeBlockType::Gallery)->at(1)->create();
    });

    // I18N-2 again, on the styles this issue added. Greek capitals drop their
    // accents, and a `text-transform` in a block stylesheet would apply to the
    // operator's own heading — the one string on the page they cannot work
    // around by typing differently.
    foreach (['/no-caps-blocks?lang=el', '/no-caps-blocks?lang=en'] as $path) {
        $body = strtolower((string) get(HostedRequest::url($path))->getContent());

        expect($body)->not->toContain('text-transform')->and($body)->not->toContain('uppercase');
    }
})->group('fast');

it('serves a read-only operator page with its blocks intact', function (): void {
    $tenant = OperatorPage::operator('lapsed-blocks');
    $tenant->forceFill(['status' => TenantStatus::ReadOnly])->save();

    OperatorPage::as($tenant, function (): void {
        HomePageBlock::factory()->saying('Η ιστορία μας', 'Our story')->create();
    });

    // HOS-10 survives #102: the page is complete and only the booking is gone.
    // A block system that hid an operator's own writing when their subscription
    // lapsed would punish the tourist for the operator's billing.
    get(HostedRequest::url('/lapsed-blocks?lang=el'))
        ->assertOk()
        ->assertSee('Η ιστορία μας', escape: false)
        ->assertSee(__('hosted.read_only', ['email' => $tenant->email], 'el'), escape: false);
})->group('fast');
