<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Hosted\Actions\BuildHomePage;
use App\Domain\Hosted\Actions\SaveHomePage;
use App\Domain\Hosted\Support\BlockSettings;
use App\Enums\HomeBlockType;
use App\Enums\ProductCategory;
use App\Filament\Forms\TranslatableInput;
use App\Models\HomePageBlock;
use App\Models\Port;
use App\Support\Tenancy;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

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
            'heading' => $block->getTranslations('heading'),
            'body' => $block->getTranslations('body'),
            'image_path' => $block->image_path,
            'images' => $block->images ?? [],
            'settings' => $block->settings(),
        ])->all();
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
        $blocks = array_values((array) ($state['blocks'] ?? []));

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
                ->options(HomeBlockType::options())
                ->required()
                ->native(false)
                // The rest of the fields key off this, so the form has to know
                // the moment it changes rather than on the next round trip.
                ->live(),

            Toggle::make('is_visible')
                ->label(__('home_page.form.is_visible.label'))
                ->helperText(__('home_page.form.is_visible.help'))
                ->default(true),

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
                ->visible(fn (Get $get): bool => HomeBlockType::tryFrom((string) $get('type'))?->hasImage() ?? false),

            // --- hero ---

            Select::make('settings.cta')
                ->label(__('home_page.form.cta.label'))
                ->helperText(__('home_page.form.cta.help'))
                ->options([
                    'trips' => __('home_page.form.cta.options.trips'),
                    'contact' => __('home_page.form.cta.options.contact'),
                    'none' => __('home_page.form.cta.options.none'),
                ])
                ->default('trips')
                ->native(false)
                ->visible(fn (Get $get): bool => $get('type') === HomeBlockType::Hero->value),

            // --- trips ---

            Select::make('settings.source')
                ->label(__('home_page.form.source.label'))
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

            // --- contact ---

            Toggle::make('settings.show_phone')
                ->label(__('home_page.form.show_phone.label'))
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
