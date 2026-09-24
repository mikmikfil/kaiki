<?php

declare(strict_types=1);

use App\Domain\Hosted\Actions\SaveHomePage;
use App\Enums\CrewSpecialty;
use App\Enums\HomeBlockType;
use App\Enums\Role;
use App\Enums\VesselLicence;
use App\Filament\App\Pages\AboutPage;
use App\Models\HomePageBlock;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use Livewire\Livewire;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Σχετικά με εμάς» (Mike, 2026-09-24)
|--------------------------------------------------------------------------
|
| The home page's sections on a page of their own, plus five new ones: a
| timeline the operator writes, and four that fill themselves from «Σκάφη»,
| «Ομάδα», the business details and «Λιμάνια».
|
| What these guard: the two pages never bleed into each other (the same table
| holds both), the page and its menu link exist only once the operator has
| saved one, and the mounts show what the records say — no more, no less.
|
*/

/**
 * Save one about page for an operator.
 *
 * @param  list<array<string, mixed>>  $blocks
 */
function aboutPage(Tenant $tenant, array $blocks): void
{
    OperatorPage::as($tenant, function () use ($blocks): void {
        app(SaveHomePage::class)($blocks, HomePageBlock::PAGE_ABOUT);
    });
}

it('is not there, and not in the menu, until the operator saves one', function (): void {
    OperatorPage::operator('no-about');

    get(HostedRequest::url('/no-about/about'))->assertNotFound();

    expect(get(HostedRequest::url('/no-about'))->assertOk()->getContent())
        ->not->toContain('/no-about/about');
});

it('serves the saved page and puts it in the menu', function (): void {
    $tenant = OperatorPage::operator('with-about');

    aboutPage($tenant, [OperatorPage::input(HomeBlockType::Story, [
        'heading' => ['el' => 'Πώς ξεκινήσαμε', 'en' => 'How we started'],
        'body' => ['el' => 'Με μια ψαρόβαρκα.', 'en' => 'With a fishing boat.'],
    ])]);

    get(HostedRequest::url('/with-about/about'))->assertOk()->assertSee('Πώς ξεκινήσαμε')->assertSee('Με μια ψαρόβαρκα.');

    // The home page links to it, and does not show its sections.
    $home = (string) get(HostedRequest::url('/with-about'))->assertOk()->getContent();

    expect($home)->toContain('/with-about/about')
        ->and($home)->not->toContain('Πώς ξεκινήσαμε');
});

it('keeps it out of the menu when every section on it is switched off', function (): void {
    $tenant = OperatorPage::operator('hidden-about');

    aboutPage($tenant, [OperatorPage::input(HomeBlockType::Story, ['is_visible' => false])]);

    get(HostedRequest::url('/hidden-about/about'))->assertNotFound();
    expect(get(HostedRequest::url('/hidden-about'))->getContent())->not->toContain('/hidden-about/about');
});

it('saves one page without touching the other', function (): void {
    $tenant = OperatorPage::operator('two-pages');

    OperatorPage::as($tenant, function (): void {
        app(SaveHomePage::class)([OperatorPage::input(HomeBlockType::Hero)], HomePageBlock::PAGE_HOME);
        app(SaveHomePage::class)([OperatorPage::input(HomeBlockType::Story), OperatorPage::input(HomeBlockType::Timeline)], HomePageBlock::PAGE_ABOUT);

        // Saving the home page again replaces the home page only.
        app(SaveHomePage::class)([OperatorPage::input(HomeBlockType::Faq)]);

        expect(HomePageBlock::query()->onPage(HomePageBlock::PAGE_HOME)->pluck('type')->all())->toBe([HomeBlockType::Faq])
            ->and(HomePageBlock::query()->onPage(HomePageBlock::PAGE_ABOUT)->pluck('type')->all())->toBe([HomeBlockType::Story, HomeBlockType::Timeline]);
    });
});

it('leaves the search out of the masthead on the about page', function (): void {
    $tenant = OperatorPage::operator('about-hero');

    aboutPage($tenant, [OperatorPage::input(HomeBlockType::Hero, ['heading' => ['el' => 'Τρεις γενιές', 'en' => 'Three generations']])]);

    $about = (string) get(HostedRequest::url('/about-hero/about'))->assertOk()->getContent();

    expect($about)->toContain('Τρεις γενιές')
        ->toContain('hero-plain')
        // The markup, not the stylesheet, which styles it for the home page.
        ->and($about)->not->toContain('class="hero-search"');
});

it('shows the years of the timeline in the order written, the last as now', function (): void {
    $tenant = OperatorPage::operator('about-years');

    aboutPage($tenant, [OperatorPage::input(HomeBlockType::Timeline, ['items' => [
        ['year' => '1968', 'title' => ['el' => 'Η πρώτη βάρκα', 'en' => 'The first boat'], 'text' => ['el' => 'Στη Σαλαμίνα.', 'en' => 'On Salamina.']],
        ['year' => '2026', 'title' => ['el' => 'Σήμερα', 'en' => 'Today']],
        // No title: dropped, not printed as an empty year.
        ['year' => '1999'],
    ]])]);

    $page = (string) get(HostedRequest::url('/about-years/about'))->assertOk()->getContent();
    $start = (int) strpos($page, '<section class="block timeline-block');
    $html = substr($page, $start, (int) strpos($page, '</section>', $start) - $start);

    expect(strpos($html, '1968'))->toBeLessThan(strpos($html, '2026'))
        ->and($html)->toContain('Η πρώτη βάρκα')
        ->and($html)->toContain('is-now')
        ->and($html)->not->toContain('1999');
});

it('shows the ticked boats with their licence and length, and only those', function (): void {
    $tenant = OperatorPage::operator('about-fleet');

    $ids = [];

    OperatorPage::as($tenant, function () use (&$ids): void {
        $ids['shown'] = Vessel::factory()->create(['name' => 'Θάλασσα', 'length_cm' => 1150, 'capacity_max' => 12, 'licence_type' => VesselLicence::DayCruise])->getKey();
        $ids['hidden'] = Vessel::factory()->create(['name' => 'Κρυφό'])->getKey();
    });

    aboutPage($tenant, [OperatorPage::input(HomeBlockType::Fleet, ['settings' => ['vessel_ids' => [(string) $ids['shown']]]])]);

    get(HostedRequest::url('/about-fleet/about'))->assertOk()
        ->assertSee('Θάλασσα')
        ->assertSee('11,5 μ.')
        ->assertSee('Ημερόπλοιο')
        ->assertDontSee('Κρυφό');
});

it('shows captains before deckhands, with their words, and nobody else', function (): void {
    $tenant = OperatorPage::operator('about-crew');

    User::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => 'Άλφα Ναύτης', 'specialty' => CrewSpecialty::Deckhand]);
    User::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => 'Ωμέγα Κυβερνήτης', 'specialty' => CrewSpecialty::Captain, 'bio' => ['el' => 'Ξέρει κάθε σπηλιά.', 'en' => 'Knows every cave.']]);
    User::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => 'Λογίστρια Γραφείου', 'specialty' => CrewSpecialty::Other]);
    User::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => 'Χωρίς Ειδικότητα', 'specialty' => null]);

    // Another operator's captain, who must never appear here.
    User::factory()->create(['tenant_id' => Tenant::factory()->create()->getKey(), 'name' => 'Ξένος Κυβερνήτης', 'specialty' => CrewSpecialty::Captain]);

    aboutPage($tenant, [OperatorPage::input(HomeBlockType::Crew)]);

    $html = (string) get(HostedRequest::url('/about-crew/about'))->assertOk()->getContent();

    expect(strpos($html, 'Ωμέγα Κυβερνήτης'))->toBeLessThan(strpos($html, 'Άλφα Ναύτης'))
        ->and($html)->toContain('Ξέρει κάθε σπηλιά.')
        // No photograph: the initials instead.
        ->and($html)->toContain('ΩΚ')
        ->and($html)->not->toContain('Λογίστρια Γραφείου')
        ->and($html)->not->toContain('Χωρίς Ειδικότητα')
        ->and($html)->not->toContain('Ξένος Κυβερνήτης');
});

it('lists the business details and the meeting point from the records', function (): void {
    $tenant = OperatorPage::operator('about-papers');
    $tenant->forceFill(['legal_name' => 'ΘΑΛΑΣΣΑ ΙΚΕ', 'vat_number' => '801234567'])->save();

    OperatorPage::as($tenant, function (): void {
        Port::factory()->create(['name' => ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'], 'address' => 'Ακτή Θεμιστοκλέους 42', 'is_active' => true, 'lat' => 37.9339, 'lng' => 23.6469]);
    });

    aboutPage($tenant, [
        OperatorPage::input(HomeBlockType::Credentials, ['body' => ['el' => 'Κάθε επιβάτης είναι ασφαλισμένος.', 'en' => 'Every passenger is insured.']]),
        OperatorPage::input(HomeBlockType::MeetingPoint, ['heading' => null]),
    ]);

    get(HostedRequest::url('/about-papers/about'))->assertOk()
        ->assertSee('ΘΑΛΑΣΣΑ ΙΚΕ')
        ->assertSee('ΑΦΜ 801234567')
        ->assertSee('Κάθε επιβάτης είναι ασφαλισμένος.')
        // No heading of its own: the port's name.
        ->assertSee('Μαρίνα Ζέας')
        ->assertSee('Ακτή Θεμιστοκλέους 42')
        ->assertSee('37°56′02″ Β', false);
});

it('opens the editor on a starting layout, and saves it to the about page only', function (): void {
    $user = OperatorUser::withRole(Role::Owner, OperatorPage::operator('about-editor'));
    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    $component = Livewire::actingAs($user)->test(AboutPage::class);

    /** @var array<array-key, array<string, mixed>> $blocks */
    $blocks = (array) $component->get('data.blocks');
    $types = array_values(array_map(static fn (array $block): mixed => $block['type'] ?? null, $blocks));

    expect($types)->toBe(array_map(static fn (HomeBlockType $type): string => $type->value, HomeBlockType::aboutLayout()))
        ->and(HomePageBlock::query()->count())->toBe(0);

    // Nothing invented: no lang key printed where a section has no starting line.
    foreach ($component->get('data.blocks') as $block) {
        foreach ((array) ($block['heading'] ?? []) as $text) {
            expect((string) $text)->not->toContain('hosted.');
        }
    }
});
