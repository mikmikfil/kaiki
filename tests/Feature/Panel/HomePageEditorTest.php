<?php

declare(strict_types=1);

use App\Enums\HomeBlockType;
use App\Enums\Role;
use App\Filament\App\Pages\HomePage;
use App\Models\HomePageBlock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The home-page editor, for the sections of 16 September
|--------------------------------------------------------------------------
|
| Every word of the new sections is the operator's and is written on this
| screen: the small line above the heading, the heading, the lead, the buttons
| with their labels and destinations, every figure, step, reason and review.
| These tests drive the real form — its validation, its repeaters and the
| mapping from one repeater per type onto the one `items` column — and then look
| at the operator's public page, because a form that saves is not the same claim
| as a page that shows what was saved.
|
*/

function homeEditorAs(User $user): Testable
{
    // `Livewire::test()` does not run the panel middleware, so the tenant is
    // initialised by hand, as `ResolveTenant` would.
    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    // A new operator's editor opens on the default page; these tests write a
    // page of their own, so they start from an empty list rather than one the
    // form would merge theirs into.
    return Livewire::actingAs($user)->test(HomePage::class)->set('data.blocks', []);
}

/**
 * A repeater's state, keyed the way Filament keys it.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, array<string, mixed>>
 */
function rows(array $rows): array
{
    $keyed = [];

    foreach ($rows as $i => $row) {
        $keyed["row-{$i}"] = $row;
    }

    return $keyed;
}

/** @return array{el: string, en: string} */
function inBoth(string $el, string $en): array
{
    return ['el' => $el, 'en' => $en];
}

/**
 * One block as the form holds it, with every field a type does not show left
 * at its empty value.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function editorBlock(HomeBlockType $type, array $fields = []): array
{
    // The type last: choosing a type fills a new section's empty fields with
    // starting content, so it is set after the fields this test writes itself —
    // which is also the order in which it happens to an operator who changes
    // the type of a section they have already filled in.
    return [
        'is_visible' => true,
        'eyebrow' => inBoth('', ''),
        'heading' => inBoth('', ''),
        'body' => inBoth('', ''),
        'image_alt' => inBoth('', ''),
        ...$fields,
        'type' => $type->value,
    ];
}

function editorOperator(string $slug): User
{
    return OperatorUser::withRole(Role::Owner, OperatorPage::operator($slug));
}

it('saves all five new sections, every text in both languages', function (): void {
    Storage::fake('public');

    $user = editorOperator('editor-five');

    homeEditorAs($user)
        ->fillForm(['blocks' => rows([
            editorBlock(HomeBlockType::Stats, [
                'stats_items' => rows([['value' => inBoth('30+', '30+'), 'label' => inBoth('χρόνια', 'years')]]),
            ]),
            editorBlock(HomeBlockType::Steps, [
                'eyebrow' => inBoth('Πώς λειτουργεί', 'How it works'),
                'heading' => inBoth('Τρία βήματα', 'Three steps'),
                'steps_items' => rows([['title' => inBoth('Διαλέξτε', 'Choose'), 'text' => inBoth('Κείμενο', 'Text')]]),
            ]),
            editorBlock(HomeBlockType::Features, [
                'heading' => inBoth('Γιατί εμάς', 'Why us'),
                'settings' => ['dark' => true],
                'features_items' => rows([['icon' => 'anchor', 'title' => inBoth('Πλήρωμα', 'Crew'), 'text' => inBoth('Έμπειρο', 'Seasoned')]]),
            ]),
            editorBlock(HomeBlockType::Testimonials, [
                'heading' => inBoth('Κριτικές', 'Reviews'),
                'testimonials_items' => rows([['quote' => inBoth('Υπέροχα', 'Lovely'), 'name' => 'Ελένη Π.', 'trip' => inBoth('Νησιά', 'Islands'), 'rating' => 4, 'avatar' => null]]),
            ]),
            editorBlock(HomeBlockType::Cta, [
                'heading' => inBoth('Όλο το σκάφος', 'The whole boat'),
                'image_path' => [UploadedFile::fake()->image('band.jpg', 1600, 900)],
                'image_alt' => inBoth('Το σκάφος', 'The boat'),
                'buttons' => rows([['label' => inBoth('Ζητήστε προσφορά', 'Ask for a quote'), 'target' => 'contact', 'product_id' => null, 'path' => null]]),
            ]),
        ])])
        ->call('save')
        ->assertHasNoFormErrors();

    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    // Keyed by type rather than read by position: the order is the Action's
    // concern, asserted in `SaveHomePageTest`, and Livewire's test harness
    // re-keys a repeater row that carries an upload.
    $blocks = HomePageBlock::query()->get()->keyBy(fn (HomePageBlock $block): string => $block->type->value);

    expect($blocks->keys()->sort()->values()->all())->toBe(['cta', 'features', 'stats', 'steps', 'testimonials'])
        ->and($blocks['stats']->entries()[0]['value'])->toBe(inBoth('30+', '30+'))
        ->and($blocks['steps']->getTranslations('eyebrow'))->toBe(inBoth('Πώς λειτουργεί', 'How it works'))
        ->and($blocks['features']->setting('dark'))->toBeTrue()
        ->and($blocks['features']->entries()[0]['icon'])->toBe('anchor')
        ->and($blocks['testimonials']->entries()[0]['rating'])->toBe(4)
        ->and($blocks['cta']->image_path)->toStartWith('home/')
        ->and($blocks['cta']->getTranslations('image_alt'))->toBe(inBoth('Το σκάφος', 'The boat'))
        ->and($blocks['cta']->buttonEntries()[0]['target'])->toBe('contact');
})->group('fast');

it('requires a photograph for the call-to-action band', function (): void {
    homeEditorAs(editorOperator('editor-band'))
        ->fillForm(['blocks' => rows([editorBlock(HomeBlockType::Cta, ['heading' => inBoth('Τίτλος', 'Heading'), 'buttons' => []])])])
        ->call('save')
        ->assertHasFormErrors(['blocks.row-0.image_path' => 'required']);
})->group('fast');

it('refuses a figure too long for the card', function (): void {
    homeEditorAs(editorOperator('editor-figure'))
        ->fillForm(['blocks' => rows([editorBlock(HomeBlockType::Stats, [
            'stats_items' => rows([['value' => inBoth(str_repeat('9', 17), '9'), 'label' => inBoth('', '')]]),
        ])])])
        ->call('save')
        ->assertHasFormErrors(['blocks.row-0.stats_items.row-0.value.el' => 'max']);
})->group('fast');

it('refuses a button path that names another site', function (): void {
    homeEditorAs(editorOperator('editor-path'))
        ->fillForm(['blocks' => rows([editorBlock(HomeBlockType::Hero, [
            'buttons' => rows([['label' => inBoth('Έξω', 'Out'), 'target' => 'page', 'product_id' => null, 'path' => '//evil.example']]),
        ])])])
        ->call('save')
        ->assertHasFormErrors(['blocks.row-0.buttons.row-0.path']);
})->group('fast');

it('refuses more reviews than the section shows', function (): void {
    $review = ['quote' => inBoth('Καλά', 'Good'), 'name' => 'Α', 'trip' => inBoth('', ''), 'rating' => 5, 'avatar' => null];

    homeEditorAs(editorOperator('editor-reviews'))
        ->fillForm(['blocks' => rows([editorBlock(HomeBlockType::Testimonials, [
            'testimonials_items' => rows([$review, $review, $review, $review]),
        ])])])
        ->call('save')
        ->assertHasFormErrors(['blocks.row-0.testimonials_items' => 'max']);
})->group('fast');

it('hides a section on the public page when its switch is turned off, and shows it again', function (): void {
    $user = editorOperator('editor-toggle');
    $steps = static fn (bool $visible): array => editorBlock(HomeBlockType::Steps, [
        'is_visible' => $visible,
        'heading' => inBoth('Τα βήματά μας', 'Our steps'),
        'steps_items' => rows([['title' => inBoth('Ελάτε νωρίς', 'Come early'), 'text' => inBoth('', '')]]),
    ]);

    homeEditorAs($user)->fillForm(['blocks' => rows([$steps(false)])])->call('save')->assertHasNoFormErrors();

    get(HostedRequest::url('/editor-toggle?lang=el'))->assertOk()->assertDontSee('Ελάτε νωρίς', escape: false);

    homeEditorAs($user)->fillForm(['blocks' => rows([$steps(true)])])->call('save')->assertHasNoFormErrors();

    get(HostedRequest::url('/editor-toggle?lang=el'))->assertOk()->assertSee('Ελάτε νωρίς', escape: false);
})->group('fast');

it('starts a new steps section with content in both languages', function (): void {
    $component = homeEditorAs(editorOperator('editor-prefill'))
        ->fillForm(['blocks' => rows([editorBlock(HomeBlockType::Story)])])
        ->fillForm(['blocks.row-0.type' => HomeBlockType::Steps->value]);

    $state = $component->get('data.blocks.row-0');

    expect($state['heading'])->toBe([
        'el' => __('hosted.blocks.steps.heading', [], 'el'),
        'en' => __('hosted.blocks.steps.heading', [], 'en'),
    ])
        ->and($state['steps_items'])->toHaveCount(3);
})->group('fast');

it('opens a hero saved before the buttons existed with its button, editable', function (): void {
    $user = editorOperator('editor-legacy');

    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));
    HomePageBlock::factory()->ofType(HomeBlockType::Hero)->create();

    $blocks = Livewire::actingAs($user)->test(HomePage::class)->get('data.blocks');
    $buttons = array_values((array) reset($blocks)['buttons']);

    expect($buttons)->toHaveCount(1)
        ->and($buttons[0]['target'])->toBe('trips')
        ->and($buttons[0]['label']['el'])->toBe(__('hosted.blocks.hero.cta.trips', [], 'el'));
})->group('fast');
