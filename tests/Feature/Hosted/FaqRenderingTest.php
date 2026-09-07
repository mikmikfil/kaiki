<?php

declare(strict_types=1);

use App\Domain\Hosted\Actions\BuildFaqList;
use App\Enums\HomeBlockType;
use App\Models\Faq;
use App\Models\HomePageBlock;
use App\Models\Product;
use App\Support\Tenancy;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| #103: which answers appear on which page
|--------------------------------------------------------------------------
|
| The FAQ is a small feature with one decision in it, and the decision is the
| nullable `product_id`: an entry is about the operator unless it names a trip.
| Everything asserted here follows from that.
|
| **The product-page half is asserted against the Action rather than against a
| page**, because the hosted product page is #104 and does not exist yet. That
| is a real gap and it is stated rather than papered over: what is proved here
| is that `BuildFaqList` answers a product with that product's entries plus the
| tenant-wide ones, in that order, and #104 renders what it is handed. The
| rendering half — the section, its markup and its JSON-LD — is proved on the
| home page, through the same partial the product page will include.
|
*/

it('shows only the tenant-wide entries on the home page', function (): void {
    $tenant = OperatorPage::operator('tenant-wide');

    OperatorPage::as($tenant, function (): void {
        $product = Product::factory()->create();

        Faq::factory()->asking('Πού συναντιόμαστε;', 'Where do we meet?')->create();
        Faq::factory()
            ->forProduct($product)
            ->asking('Σταματάει για μπάνιο;', 'Does it stop for a swim?')
            ->create();

        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();
    });

    $response = get(HostedRequest::url('/tenant-wide?lang=el'));

    // The general answer belongs on a page with no product on it; the
    // trip-specific one does not, because "yes, we stop for a swim" is true of
    // one trip and false of the next.
    $response->assertOk()
        ->assertSee('Πού συναντιόμαστε;', escape: false)
        ->assertDontSee('Σταματάει για μπάνιο;', escape: false);
})->group('fast');

it('hands a product its own entries and the tenant-wide ones, its own first', function (): void {
    $tenant = OperatorPage::operator('product-faq');

    Tenancy::forTenant($tenant, function (): void {
        $sunset = Product::factory()->create();
        $other = Product::factory()->create();

        Faq::factory()->at(0)->asking('Τι να φέρω;', 'What should I bring?')->create();
        Faq::factory()->at(1)->asking('Δέχεστε παιδιά;', 'Are children welcome?')->create();
        Faq::factory()->forProduct($sunset)->at(0)->asking('Έχει φαγητό;', 'Is food included?')->create();
        Faq::factory()->forProduct($other)->at(0)->asking('Άλλη εκδρομή;', 'A different trip?')->create();

        $entries = app(BuildFaqList::class)($sunset);

        // This product's answer first, then the operator's own two in the order
        // the operator put them. The other product's entry is absent entirely.
        expect($entries->map(fn (Faq $faq): string => (string) $faq->getTranslation('question', 'en'))->all())
            ->toBe(['Is food included?', 'What should I bring?', 'Are children welcome?']);
    });
})->group('fast');

it('renders no FAQ section at all when there is nothing published', function (): void {
    $tenant = OperatorPage::operator('no-questions');

    OperatorPage::as($tenant, function (): void {
        // Unpublished, so it exists in the panel and on no page.
        Faq::factory()->unpublished()->asking('Κρυφή;', 'Hidden?')->create();

        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create([
            'heading' => ['el' => 'Συχνές ερωτήσεις', 'en' => 'Questions'],
        ]);
    });

    $response = get(HostedRequest::url('/no-questions?lang=el'));

    // Not an empty heading and not "no questions yet". An operator with nothing
    // published has a page with no FAQ section, which is the page they had
    // before the block existed.
    $response->assertOk()
        ->assertDontSee('Κρυφή;', escape: false)
        ->assertDontSee('Συχνές ερωτήσεις', escape: false)
        // The element, not the class name: the layout's stylesheet mentions
        // `.faq-list` on every page whether or not a section uses it.
        ->assertDontSee('<section class="block faq"', escape: false);
})->group('fast');

it('renders the entries in the operator\'s order, not in the order they were written', function (): void {
    $tenant = OperatorPage::operator('ordered-faq');

    OperatorPage::as($tenant, function (): void {
        Faq::factory()->at(2)->asking('Τρίτη', 'Third')->create();
        Faq::factory()->at(0)->asking('Πρώτη', 'First')->create();
        Faq::factory()->at(1)->asking('Δεύτερη', 'Second')->create();

        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();
    });

    $body = (string) get(HostedRequest::url('/ordered-faq?lang=en'))->getContent();

    expect(mb_strpos($body, 'First'))->toBeLessThan(mb_strpos($body, 'Second'))
        ->and(mb_strpos($body, 'Second'))->toBeLessThan(mb_strpos($body, 'Third'));
})->group('fast');

it('serves the answers in the visitor\'s language', function (): void {
    $tenant = OperatorPage::operator('bilingual-faq');

    OperatorPage::as($tenant, function (): void {
        Faq::factory()
            ->asking('Χρειάζεται κολύμπι;', 'Do I need to swim?', 'Όχι, δίνουμε σωσίβια.', 'No, we hand out life jackets.')
            ->create();

        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();
    });

    get(HostedRequest::url('/bilingual-faq?lang=el'))
        ->assertSee('Χρειάζεται κολύμπι;', escape: false)
        ->assertSee('Όχι, δίνουμε σωσίβια.', escape: false);

    get(HostedRequest::url('/bilingual-faq?lang=en'))
        ->assertSee('Do I need to swim?', escape: false)
        ->assertSee('No, we hand out life jackets.', escape: false);
})->group('fast');

it('escapes an operator who pastes markup into an answer', function (): void {
    $tenant = OperatorPage::operator('escaped-faq');

    OperatorPage::as($tenant, function (): void {
        Faq::factory()
            ->asking(
                'Ερώτηση <b>έντονη</b>;',
                'A <b>bold</b> question?',
                '<script>alert(1)</script> Η απάντηση.',
                '<script>alert(1)</script> The answer.',
            )
            ->create();

        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();
    });

    $body = (string) get(HostedRequest::url('/escaped-faq?lang=en'))->getContent();

    // The same pair of assertions as #102's: the tags arrive as text, and the
    // page still has no script element of its own — HOS-4's claim, restated
    // here because the JSON-LD block is the first `<script>` these pages have
    // ever had, and it is the one thing that could have made the claim false.
    expect($body)->toContain('&lt;script&gt;')
        ->and($body)->toContain('&lt;b&gt;bold&lt;/b&gt;')
        ->and(substr_count($body, '<script'))->toBe(1)
        ->and($body)->toContain('<script type="application/ld+json"');
})->group('fast');

it('keeps one operator\'s answers off another operator\'s page', function (): void {
    $mine = OperatorPage::operator('mine-faq');
    $theirs = OperatorPage::operator('theirs-faq');

    OperatorPage::as($mine, function (): void {
        Faq::factory()->asking('Δική μου ερώτηση;', 'My question?')->create();
        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();
    });

    OperatorPage::as($theirs, function (): void {
        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();
    });

    get(HostedRequest::url('/theirs-faq?lang=en'))
        ->assertOk()
        ->assertDontSee('My question?', escape: false);
})->group('fast');
