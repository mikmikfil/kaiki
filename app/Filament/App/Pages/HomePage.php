<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Hosted\Actions\BuildHomePage;
use App\Domain\Hosted\Actions\SaveHomePage;
use App\Domain\Hosted\Support\BlockItems;
use App\Domain\Hosted\Support\BlockSettings;
use App\Enums\HomeBlockType;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Filament\Forms\TranslatableInput;
use App\Models\HomePageBlock;
use App\Models\Port;
use App\Models\Product;
use App\Rules\EmbeddableVideoUrl;
use App\Support\Tenancy;
use Closure;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The home-page editor on `/app`.
 *
 * ## A reorderable list of five kinds of thing, and no rich text
 *
 * The requirement this screen exists to satisfy is "an operator can write their
 * own landing page". The obvious build is a WYSIWYG field, and
 * {@see HomeBlockType} records at length why it is not this one: a block is
 * structured data, so the markup stays ours in the page, in the meta
 * description, in the WordPress SEO sync and in an email, and an operator
 * cannot break their own layout or paste a script into a page HOS-8 spent an
 * issue hardening.
 *
 * ## A Page and not a Resource, for the same reason as Branding
 *
 * There is one home page. A resource would give an operator a list, a create
 * button and a delete button for a thing there is exactly one of. What the
 * operator thinks they are doing is editing *the* page, so that is the screen.
 *
 * ## The form never sees a `HomePageBlock`
 *
 * `mount()` fills the repeater from the model's own columns and `save()` hands
 * the raw state to {@see SaveHomePage}. Filament's `Repeater::relationship()`
 * would be shorter and would write rows itself — skipping settings
 * normalisation, the translatable-blank rule and the whole-page replace that
 * makes reordering safe. The same three would then have to be re-implemented
 * for the API.
 */
class HomePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-home-modern';

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 88;

    protected static string $view = 'filament.app.pages.home-page';

    /**
     * The form state.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('home_page.nav');
    }

    public function getTitle(): string
    {
        return __('home_page.title');
    }

    public function getSubheading(): ?string
    {
        return __('home_page.subtitle');
    }

    /**
     * SEC-3: crew never reach this page, and Filament asks before rendering it.
     *
     * Both hooks, for the reason {@see Branding::canAccess()} gives: `canAccess`
     * keeps the item out of the navigation and refuses the URL, and `mount()`
     * refuses again for a link that was already open when a role changed.
     */
    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', HomePageBlock::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->getForm('form')?->fill(['blocks' => $this->currentBlocks()]);
    }

    /**
     * The stored page, or the default for an operator who has never saved one.
     *
     * The default is what their guests are already being served
     * ({@see BuildHomePage}), so the editor opens on the live page rather than
     * on a blank canvas the operator has to rebuild before they can improve it.
     *
     * @return list<array<string, mixed>>
     */
    protected function currentBlocks(): array
    {
        $stored = HomePageBlock::query()->orderBy('sort_order')->orderBy('id')->get();

        if ($stored->isEmpty()) {
            $tenant = Tenancy::current();

            $stored = $tenant === null
                ? collect()
                : app(BuildHomePage::class)->default($tenant);
        }

        return $stored->map(fn (HomePageBlock $block): array => [
            'type' => $block->type->value,
            'is_visible' => $block->is_visible,
            'eyebrow' => $block->getTranslations('eyebrow'),
            'heading' => $block->getTranslations('heading'),
            'body' => $block->getTranslations('body'),
            'image_path' => $block->image_path,
            'image_alt' => $block->getTranslations('image_alt'),
            'video_path' => $block->video_path,
            'video_url' => $block->video_url,
            'images' => $block->images ?? [],
            'buttons' => $this->buttonsFor($block),
            // One repeater per list-shaped type, each with its own fields, and
            // all of them written back to the one `items` column on save.
            ...($block->type->hasItems() ? [self::itemsKey($block->type) => $block->entries()] : []),
            'settings' => $block->settings(),
        ])->all();
    }

    /**
     * The name of the repeater that edits one type's `items`.
     *
     * Each list-shaped type has different fields, and a single `items` repeater
     * whose schema switched on the type would carry a review's fields into a
     * row of figures the moment an operator changed the type selector.
     */
    public static function itemsKey(HomeBlockType $type): string
    {
        return $type->value . '_items';
    }

    /**
     * The hero's and the band's buttons, as the form edits them.
     *
     * A hero saved before 16 September has no `buttons` but does have a
     * `settings.cta`; it opens here as the same button, with the label it was
     * already showing in both languages, so the operator sees — and can change
     * or delete — what their guests see.
     *
     * @return list<array<string, mixed>>
     */
    protected function buttonsFor(HomePageBlock $block): array
    {
        $buttons = $block->buttonEntries();

        if ($buttons === [] && $block->type === HomeBlockType::Hero && in_array($block->setting('cta'), ['trips', 'contact'], true)) {
            $cta = (string) $block->setting('cta');

            return [[
                'label' => [
                    'el' => (string) __("hosted.blocks.hero.cta.{$cta}", [], 'el'),
                    'en' => (string) __("hosted.blocks.hero.cta.{$cta}", [], 'en'),
                ],
                'target' => $cta,
                'product_id' => null,
                'path' => null,
            ]];
        }

        return $buttons;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Repeater::make('blocks')
                    ->label(__('home_page.form.blocks.label'))
                    ->helperText(__('home_page.form.blocks.help'))
                    ->schema($this->blockSchema())
                    ->reorderable()
                    ->collapsible()
                    // The label on a collapsed block is the operator's own
                    // heading, so a page of six collapsed rows is readable.
                    // Falling back to the type's label rather than to "Block 4"
                    // keeps a gallery legible when it has no heading.
                    ->itemLabel(fn (array $state): string => $this->itemLabel($state))
                    ->addActionLabel(__('home_page.form.blocks.add'))
                    ->defaultItems(0)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->getForm('form')?->getState() ?? [];

        /** @var list<array<string, mixed>> $blocks */
        $blocks = array_map(static function (mixed $block): array {
            $block = (array) $block;
            $type = HomeBlockType::tryFrom((string) ($block['type'] ?? ''));

            // The type's own repeater becomes `items`; the others' leftovers,
            // from before a type was changed, are not carried.
            $block['items'] = $type !== null && $type->hasItems()
                ? ($block[self::itemsKey($type)] ?? [])
                : null;

            return $block;
        }, array_values((array) ($state['blocks'] ?? [])));

        $count = app(SaveHomePage::class)($blocks);

        // Refilled from the database rather than left as submitted, so that the
        // normalisation the Action applied is what the operator sees — a
        // reconciled setting that silently differed from the form would be
        // discovered on the public page.
        $this->getForm('form')?->fill(['blocks' => $this->currentBlocks()]);

        Notification::make()
            ->title(__('home_page.saved.title'))
            ->body(trans_choice('home_page.saved.body', $count, ['count' => $count]))
            ->success()
            ->send();
    }

    /**
     * The operator's own page, for the "view it" link.
     *
     * Built from the hosted host and the slug rather than from `route()`,
     * because the route is registered on that domain and `route()` called from
     * the panel would produce a URL on the panel's host — a link that 404s from
     * the one screen whose whole job is to show the operator their page.
     */
    public function publicUrl(): string
    {
        $tenant = Tenancy::current();
        $host = (string) config('kaiki.tenancy.hosted_host');

        return $tenant === null ? '#' : "https://{$host}/{$tenant->slug}";
    }

    /** @param array<string, mixed> $state */
    protected function itemLabel(array $state): string
    {
        $type = HomeBlockType::tryFrom((string) ($state['type'] ?? ''));

        if ($type === null) {
            return __('home_page.form.blocks.untitled');
        }

        $heading = $state['heading'] ?? [];
        $heading = is_array($heading) ? $heading : [];

        foreach ([app()->getLocale(), 'el', 'en'] as $locale) {
            $text = $heading[$locale] ?? null;

            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        return $type->label();
    }

    /**
     * One block's fields.
     *
     * Every field after the type selector is `visible()` on the type, which is
     * what makes five different forms out of one schema. The alternative —
     * `Repeater::blocks()` with a builder — produces a nicer editor and a
     * different state shape per block, which the Action would then have to
     * branch on twice.
     *
     * @return array<int, Component>
     */
    protected function blockSchema(): array
    {
        return [
            Select::make('type')
                ->label(__('home_page.form.type.label'))
                ->helperText(__('home_page.form.type.help'))
                ->options(HomeBlockType::options())
                ->required()
                ->native(false)
                // The rest of the fields key off this, so the form has to know
                // the moment it changes rather than on the next round trip.
                ->live()
                // A new section of the kinds added on 16 September starts with
                // content in both languages rather than as a row of empty
                // fields — the operator keeps it, changes it or deletes it, and
                // nothing on the page falls back to it later.
                ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => $this->prefill($state, $get, $set)),

            Toggle::make('is_visible')
                ->label(__('home_page.form.is_visible.label'))
                ->helperText(__('home_page.form.is_visible.help'))
                ->default(true),

            TranslatableInput::text(
                'eyebrow',
                __('home_page.form.eyebrow.label'),
                __('home_page.form.eyebrow.help'),
                required: false,
                maxLength: 60,
            )->visible(fn (Get $get): bool => HomeBlockType::tryFrom((string) $get('type'))?->hasEyebrow() ?? false),

            TranslatableInput::text(
                'heading',
                __('home_page.form.heading.label'),
                __('home_page.form.heading.help'),
                required: false,
                maxLength: 120,
            ),

            TranslatableInput::textarea(
                'body',
                __('home_page.form.body.label'),
                __('home_page.form.body.help'),
                rows: 6,
            )->visible(fn (Get $get): bool => HomeBlockType::tryFrom((string) $get('type'))?->hasProse() ?? false),

            $this->image('image_path')
                // The call-to-action band is a photograph with words on it; the
                // others' photographs are optional.
                ->required(fn (Get $get): bool => $get('type') === HomeBlockType::Cta->value)
                ->visible(fn (Get $get): bool => HomeBlockType::tryFrom((string) $get('type'))?->hasImage() ?? false),

            TranslatableInput::text(
                'image_alt',
                __('home_page.form.image_alt.label'),
                __('home_page.form.image_alt.help'),
                required: false,
                maxLength: 160,
            )->visible(fn (Get $get): bool => HomeBlockType::tryFrom((string) $get('type'))?->hasImage() ?? false),

            // The hero only. A video behind a story or a contact panel is
            // decoration competing with the words next to it; behind a masthead
            // it is the masthead.
            $this->video('video_path')
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Hero->value),

            // The same masthead, for the operator whose film is already made.
            // Most of them have one — on YouTube, in every resolution, on
            // somebody else's bandwidth — and no amount of explaining the
            // twenty-megabyte upload limit turns it into an MP4 they can
            // produce. The link is the shorter path to the same page.
            TextInput::make('video_url')
                ->label(__('home_page.form.video_url.label'))
                ->helperText(__('home_page.form.video_url.help'))
                ->placeholder('https://www.youtube.com/watch?v=...')
                ->maxLength(255)
                // Not `->url()`: that accepts every scheme and host there is,
                // and would pass a link this page cannot frame. The rule asks
                // the same parser the page does.
                ->rule(new EmbeddableVideoUrl)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Hero->value),

            // --- hero and call-to-action band: buttons ---

            // Replaced the hero's single `settings.cta` select on 16 September:
            // the operator writes each label, and picks where it goes from a
            // closed list — never a typed address, for the reason
            // `BlockSettings` gives about the hero's button.
            Repeater::make('buttons')
                ->label(__('home_page.form.buttons.label'))
                ->helperText(__('home_page.form.buttons.help'))
                ->schema([
                    TranslatableInput::text(
                        'label',
                        __('home_page.form.buttons.button_label.label'),
                        null,
                        required: true,
                        maxLength: 40,
                    ),
                    Select::make('target')
                        ->label(__('home_page.form.buttons.target.label'))
                        ->options(collect(BlockItems::TARGETS)->mapWithKeys(fn (string $target): array => [
                            $target => __("home_page.form.buttons.target.options.{$target}"),
                        ])->all())
                        ->default('trips')
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('product_id')
                        ->label(__('home_page.form.buttons.product.label'))
                        ->options(fn (): array => Product::query()
                            ->where('status', ProductStatus::Active)
                            ->orderBy('sort_order')
                            ->get()
                            ->mapWithKeys(fn (Product $product): array => [$product->id => $product->title])
                            ->all())
                        ->required(fn (Get $get): bool => $get('target') === 'trip')
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('target') === 'trip'),
                    TextInput::make('path')
                        ->label(__('home_page.form.buttons.path.label'))
                        ->helperText(__('home_page.form.buttons.path.help'))
                        ->prefix(fn (): string => $this->publicUrl() . '/')
                        ->maxLength(200)
                        ->required(fn (Get $get): bool => $get('target') === 'page')
                        // The same test `BlockItems` applies on save: a path on
                        // this site, and nothing that could name another one.
                        ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            if (filled($value) && BlockItems::path($value) === null) {
                                $fail(__('home_page.validation.path'));
                            }
                        })
                        ->visible(fn (Get $get): bool => $get('target') === 'page'),
                ])
                ->maxItems(2)
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => self::preview($state, 'label'))
                ->addActionLabel(__('home_page.form.buttons.add'))
                ->defaultItems(0)
                ->visible(fn (Get $get): bool => HomeBlockType::tryFrom((string) $get('type'))?->maxButtons() > 0),

            // --- hero: trust badges ---

            Repeater::make(self::itemsKey(HomeBlockType::Hero))
                ->label(__('home_page.form.badges.label'))
                ->helperText(__('home_page.form.badges.help'))
                ->schema([
                    $this->iconSelect(),
                    TranslatableInput::text('text', __('home_page.form.badges.text.label'), null, required: true, maxLength: 48),
                ])
                ->maxItems(HomeBlockType::Hero->maxItems())
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => self::preview($state, 'text'))
                ->addActionLabel(__('home_page.form.badges.add'))
                ->defaultItems(0)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Hero->value),

            // --- stats ---

            Repeater::make(self::itemsKey(HomeBlockType::Stats))
                ->label(__('home_page.form.stats.label'))
                ->helperText(__('home_page.form.stats.help'))
                ->schema([
                    $this->iconSelect(required: false),
                    TranslatableInput::text('value', __('home_page.form.stats.value.label'), __('home_page.form.stats.value.help'), required: true, maxLength: 16),
                    TranslatableInput::text('label', __('home_page.form.stats.caption.label'), null, required: false, maxLength: 60),
                ])
                ->maxItems(HomeBlockType::Stats->maxItems())
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => self::preview($state, 'value', 'label'))
                ->addActionLabel(__('home_page.form.stats.add'))
                ->defaultItems(0)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Stats->value),

            // --- steps ---

            Repeater::make(self::itemsKey(HomeBlockType::Steps))
                ->label(__('home_page.form.steps.label'))
                ->helperText(__('home_page.form.steps.help'))
                ->schema([
                    TranslatableInput::text('title', __('home_page.form.item_title.label'), null, required: true, maxLength: 80),
                    TranslatableInput::textarea('text', __('home_page.form.item_text.label'), __('home_page.form.item_text.help'), rows: 2),
                ])
                ->maxItems(HomeBlockType::Steps->maxItems())
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => self::preview($state, 'title'))
                ->addActionLabel(__('home_page.form.steps.add'))
                ->defaultItems(0)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Steps->value),

            // --- features ---

            Repeater::make(self::itemsKey(HomeBlockType::Features))
                ->label(__('home_page.form.features.label'))
                ->helperText(__('home_page.form.features.help'))
                ->schema([
                    $this->iconSelect(),
                    TranslatableInput::text('title', __('home_page.form.item_title.label'), null, required: true, maxLength: 80),
                    TranslatableInput::textarea('text', __('home_page.form.item_text.label'), __('home_page.form.item_text.help'), rows: 2),
                ])
                ->maxItems(HomeBlockType::Features->maxItems())
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => self::preview($state, 'title'))
                ->addActionLabel(__('home_page.form.features.add'))
                ->defaultItems(0)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Features->value),

            Toggle::make('settings.dark')
                ->label(__('home_page.form.dark.label'))
                ->helperText(__('home_page.form.dark.help'))
                ->default(false)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Features->value),

            // --- testimonials ---

            Repeater::make(self::itemsKey(HomeBlockType::Testimonials))
                ->label(__('home_page.form.testimonials.label'))
                ->helperText(__('home_page.form.testimonials.help'))
                ->schema([
                    TranslatableInput::textarea('quote', __('home_page.form.testimonials.quote.label'), __('home_page.form.testimonials.quote.help'), required: true, rows: 3),
                    TextInput::make('name')
                        ->label(__('home_page.form.testimonials.name.label'))
                        ->helperText(__('home_page.form.testimonials.name.help'))
                        ->maxLength(60),
                    TranslatableInput::text('trip', __('home_page.form.testimonials.trip.label'), null, required: false, maxLength: 80),
                    Select::make('rating')
                        ->label(__('home_page.form.testimonials.rating.label'))
                        ->options([5 => '5', 4 => '4', 3 => '3', 2 => '2', 1 => '1'])
                        ->default(5)
                        ->required()
                        ->native(false),
                    $this->image('avatar')
                        ->label(__('home_page.form.testimonials.avatar.label'))
                        ->helperText(__('home_page.form.testimonials.avatar.help'))
                        ->avatar(),
                ])
                ->maxItems(HomeBlockType::Testimonials->maxItems())
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => is_string($state['name'] ?? null) && trim($state['name']) !== ''
                    ? trim($state['name'])
                    : self::preview($state, 'quote'))
                ->addActionLabel(__('home_page.form.testimonials.add'))
                ->defaultItems(0)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Testimonials->value),

            // --- trips ---

            Select::make('settings.source')
                ->label(__('home_page.form.source.label'))
                ->helperText(__('home_page.form.source.help'))
                ->options([
                    BlockSettings::SOURCE_ALL => __('home_page.form.source.options.all'),
                    BlockSettings::SOURCE_FEATURED => __('home_page.form.source.options.featured'),
                    BlockSettings::SOURCE_CATEGORY => __('home_page.form.source.options.category'),
                ])
                ->default(BlockSettings::SOURCE_ALL)
                ->native(false)
                ->live()
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Trips->value),

            Select::make('settings.category')
                ->label(__('home_page.form.category.label'))
                ->options(ProductCategory::options())
                ->native(false)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Trips->value
                    && $get('settings.source') === BlockSettings::SOURCE_CATEGORY),

            Toggle::make('settings.show_featured')
                ->label(__('home_page.form.show_featured.label'))
                ->helperText(__('home_page.form.show_featured.help'))
                ->default(true)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Trips->value),

            TextInput::make('settings.limit')
                ->label(__('home_page.form.limit.label'))
                ->helperText(__('home_page.form.limit.help'))
                ->integer()
                ->minValue(0)
                ->maxValue(24)
                ->default(0)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Trips->value),

            // --- story ---

            Select::make('settings.image_side')
                ->label(__('home_page.form.image_side.label'))
                ->helperText(__('home_page.form.image_side.help'))
                ->options([
                    'left' => __('home_page.form.image_side.options.left'),
                    'right' => __('home_page.form.image_side.options.right'),
                ])
                ->default('right')
                ->native(false)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Story->value),

            // --- gallery ---

            Repeater::make('images')
                ->label(__('home_page.form.images.label'))
                ->helperText(__('home_page.form.images.help'))
                ->schema([
                    $this->image('path'),
                    // Per image and per locale. A gallery whose alt text is the
                    // same sentence eleven times is a gallery with no alt text,
                    // and one written only in English describes the Greek page
                    // in the wrong language.
                    TranslatableInput::text(
                        'alt',
                        __('home_page.form.images.alt.label'),
                        __('home_page.form.images.alt.help'),
                        required: false,
                        maxLength: 160,
                    ),
                ])
                ->addActionLabel(__('home_page.form.images.add'))
                ->reorderable()
                ->defaultItems(0)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Gallery->value),

            // --- faq ---

            // The block carries no content of its own, so the one thing this
            // slot has to do is say where the content is. Without it an
            // operator adds an FAQ block, sees a heading field, and concludes
            // the questions go in the heading.
            Placeholder::make('faq_hint')
                ->label(__('home_page.form.faq.label'))
                ->content(__('home_page.form.faq.help'))
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Faq->value),

            // --- contact ---

            // Το βοηθητικό κείμενο μπαίνει μόνο στο πρώτο από τα τρία: λέει
            // από πού έρχονται τα στοιχεία, και τρεις φορές η ίδια πρόταση
            // κάτω από τρεις διακόπτες διαβάζεται ως θόρυβος.
            Toggle::make('settings.show_phone')
                ->label(__('home_page.form.show_phone.label'))
                ->helperText(__('home_page.form.show_phone.help'))
                ->default(true)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Contact->value),

            Toggle::make('settings.show_email')
                ->label(__('home_page.form.show_email.label'))
                ->default(true)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Contact->value),

            Toggle::make('settings.show_address')
                ->label(__('home_page.form.show_address.label'))
                ->default(true)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Contact->value),

            Select::make('settings.meeting_point_id')
                ->label(__('home_page.form.meeting_point.label'))
                ->helperText(__('home_page.form.meeting_point.help'))
                // A `ports` row the operator already has, so this block cannot
                // disagree with the product pages about where the boat leaves.
                ->options(fn (): array => Port::query()
                    ->where('is_active', true)
                    ->get()
                    ->mapWithKeys(fn (Port $port): array => [$port->id => $port->name])
                    ->all())
                ->native(false)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Contact->value),
        ];
    }

    /**
     * One image slot.
     *
     * The public disk, not the private one branding uses: these are pictures on
     * a page a crawler reads, and streaming them through a controller would put
     * PHP in front of every photograph on the busiest page in the product.
     */
    /**
     * The hero's optional video (HOS-1).
     *
     * ## Twenty megabytes, and the limit is the point
     *
     * A masthead video is a five-second loop of water, not a film. An operator
     * who uploads a two-minute clip from their phone has made their own home
     * page unusable on the connection their guests are on — in a harbour, on
     * roaming data — and they will never see it, because their office has
     * fibre. The limit is the only thing that says so.
     *
     * `mp4` and `webm` and nothing else: between them they play everywhere, and
     * a `.mov` straight off an iPhone does not play in Chrome on Android at all.
     */
    protected function video(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->label(__('home_page.form.video.label'))
            ->helperText(__('home_page.form.video.help'))
            ->disk('public')
            ->directory('home')
            ->visibility('public')
            ->maxSize(20480)
            ->acceptedFileTypes(['video/mp4', 'video/webm']);
    }

    /** The icon beside a reason or a trust badge, from the fixed list. */
    protected function iconSelect(bool $required = true): Select
    {
        return Select::make('icon')
            ->label(__('home_page.form.icon.label'))
            ->options(collect(BlockItems::ICONS)->mapWithKeys(fn (string $icon): array => [
                $icon => __("home_page.form.icon.options.{$icon}"),
            ])->all())
            ->default($required ? 'check' : null)
            ->required($required)
            ->native(false);
    }

    /**
     * A collapsed repeater row's title: the first translated field that has
     * text, in the panel's language first.
     *
     * @param  array<string, mixed>  $state
     */
    protected static function preview(array $state, string ...$keys): ?string
    {
        $parts = [];

        foreach ($keys as $key) {
            $value = $state[$key] ?? null;

            foreach ([app()->getLocale(), 'el', 'en'] as $locale) {
                $text = is_array($value) ? ($value[$locale] ?? null) : null;

                if (is_string($text) && trim($text) !== '') {
                    $parts[] = trim($text);

                    break;
                }
            }
        }

        return $parts === [] ? null : mb_strimwidth(implode(' · ', $parts), 0, 70, '…');
    }

    /**
     * Starting content for a section the operator has just added.
     *
     * Only into fields that are still empty, so changing the type of a block
     * that already has words in it does not overwrite them. Read from the
     * `hosted.blocks` lang lines in **both** languages, because a translatable
     * field wants both and the panel is only ever in one.
     */
    protected function prefill(?string $state, Get $get, Set $set): void
    {
        $type = HomeBlockType::tryFrom((string) $state);

        if ($type === null) {
            return;
        }

        $both = static fn (string $key): array => [
            'el' => (string) __($key, [], 'el'),
            'en' => (string) __($key, [], 'en'),
        ];

        $blank = static fn (mixed $value): bool => ! is_array($value)
            || collect($value)->filter(static fn (mixed $text): bool => is_string($text) && trim($text) !== '')->isEmpty();

        $section = match ($type) {
            HomeBlockType::Steps, HomeBlockType::Features, HomeBlockType::Testimonials, HomeBlockType::Cta => $type->value,
            default => null,
        };

        if ($section === null) {
            return;
        }

        if ($blank($get('eyebrow'))) {
            $set('eyebrow', $both("hosted.blocks.{$section}.eyebrow"));
        }

        if ($section !== 'cta' && $blank($get('heading'))) {
            $set('heading', $both("hosted.blocks.{$section}.heading"));
        }

        if (in_array($type, [HomeBlockType::Steps, HomeBlockType::Features], true) && blank($get(self::itemsKey($type)))) {
            $el = (array) __("hosted.blocks.{$section}.defaults", [], 'el');
            $en = (array) __("hosted.blocks.{$section}.defaults", [], 'en');
            $items = [];

            foreach ($el as $i => $entry) {
                $items[(string) Str::uuid()] = [
                    ...($type === HomeBlockType::Features ? ['icon' => BlockItems::STARTING_FEATURE_ICONS[$i] ?? 'check'] : []),
                    'title' => ['el' => $entry['title'], 'en' => $en[$i]['title'] ?? $entry['title']],
                    'text' => ['el' => $entry['text'], 'en' => $en[$i]['text'] ?? $entry['text']],
                ];
            }

            $set(self::itemsKey($type), $items);
        }
    }

    protected function image(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->label(__('home_page.form.image.label'))
            ->helperText(__('home_page.form.image.help'))
            ->disk('public')
            ->directory('home')
            ->visibility('public')
            ->image()
            ->imageEditor()
            ->maxSize(4096)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp']);
    }
}
