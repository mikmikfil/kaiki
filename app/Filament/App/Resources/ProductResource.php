<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Catalog\Support\ProductPublishChecklist;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Filament\App\Resources\ProductResource\Pages;
use App\Filament\App\Resources\ProductResource\RelationManagers\RatePlansRelationManager;
use App\Filament\Forms\TranslatableInput;
use App\Models\CancellationPolicy;
use App\Models\Port;
use App\Models\Product;
use App\Models\VatRate;
use App\Models\Vessel;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * The trip, on `/app` (spec CAT-4, CAT-5, CAT-7, CAT-15, SEC-3, TEN-8, I18N-1).
 *
 * The operator's first real encounter with the product, and the largest form in
 * the panel. Thin all the same (CNV-5): `SaveProduct` and `SaveAgeBands` hold
 * every rule, and what is here is affordance — which fields appear for which
 * mode, and what the operator is told is still missing.
 *
 * ## The publish checklist is shown, not only enforced
 *
 * CAT-15 is a gate at the moment of publishing. Enforced alone it is a wall an
 * operator hits after filling in a long form; the checklist section turns it
 * into something they can watch while they work, so the refusal is never the
 * first they hear of it. The keys come from
 * {@see ProductPublishChecklist} and the sentences from lang files, so the API
 * can answer with the same list in error codes (CNV-11).
 *
 * ## Age bands are a repeater, not a relation manager
 *
 * Every CAT-8 rule is set-level — exactly one base band, no overlapping ranges,
 * at least one band taking a seat. A relation manager saves one row at a time,
 * which means an operator correcting two bands would be refused halfway through
 * for a state they were in the middle of leaving. The repeater hands the whole
 * set to `SaveAgeBands` in one call, which is the only way those rules can be
 * judged.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.catalogue');
    }

    public static function getNavigationLabel(): string
    {
        return __('catalog.product.nav');
    }

    public static function getModelLabel(): string
    {
        return __('catalog.product.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.product.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('catalog.product.sections.basics'))
                ->schema([
                    TranslatableInput::text(
                        'title',
                        __('catalog.product.form.title.label'),
                        __('catalog.product.form.title.help'),
                        maxLength: 160,
                        // The Greek title seeds the slug, and only the Greek
                        // one: it is the language every operator fills in, and
                        // two tabs racing to write the same field would let
                        // whichever was blurred last decide the address.
                        configure: static fn (TextInput $input, string $locale): TextInput => $locale === 'el'
                            ? $input->live(onBlur: true)->afterStateUpdated(static::fillSlug(...))
                            : $input,
                    ),

                    TextInput::make('slug')
                        ->label(__('catalog.product.form.slug.label'))
                        ->helperText(__('catalog.product.form.slug.help'))
                        ->required()
                        ->maxLength(120)
                        ->alphaDash(),

                    Select::make('vessel_id')
                        ->label(__('catalog.product.form.vessel.label'))
                        ->helperText(__('catalog.product.form.vessel.help'))
                        ->options(static::vesselOptions(...))
                        ->searchable()
                        ->preload(),

                    Select::make('category')
                        ->label(__('catalog.product.form.category.label'))
                        ->helperText(__('catalog.product.form.category.help'))
                        ->options(ProductCategory::options())
                        ->required(),

                    Select::make('mode')
                        ->label(__('catalog.product.form.mode.label'))
                        ->helperText(__('catalog.product.form.mode.help'))
                        ->options(BookingMode::options())
                        ->required()
                        ->live(),

                    Select::make('status')
                        ->label(__('catalog.product.form.status.label'))
                        ->helperText(__('catalog.product.form.status.help'))
                        ->options(ProductStatus::options())
                        ->default(ProductStatus::Draft->value)
                        ->required(),

                    TextInput::make('sort_order')
                        ->label(__('catalog.shared.sort_order.label'))
                        ->helperText(__('catalog.shared.sort_order.help'))
                        ->integer()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(65535),

                    Toggle::make('is_featured')
                        ->label(__('catalog.product.form.is_featured.label'))
                        ->helperText(__('catalog.product.form.is_featured.help')),
                ])
                ->columns(2),

            Section::make(__('catalog.product.sections.checklist'))
                ->description(__('catalog.product.checklist.intro'))
                ->schema(static::checklistSchema()),

            Section::make(__('catalog.product.sections.schedule'))
                ->schema([
                    TextInput::make('duration_minutes')
                        ->label(__('catalog.product.form.duration_minutes.label'))
                        ->helperText(__('catalog.product.form.duration_minutes.help'))
                        ->suffix(__('catalog.product.form.duration_minutes.suffix'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(65535),

                    TimePicker::make('default_start_time')
                        ->label(__('catalog.product.form.default_start_time.label'))
                        ->helperText(__('catalog.product.form.default_start_time.help'))
                        ->seconds(false)
                        ->native(false),

                    TextInput::make('check_in_offset_minutes')
                        ->label(__('catalog.product.form.check_in_offset_minutes.label'))
                        ->helperText(__('catalog.product.form.check_in_offset_minutes.help'))
                        ->suffix(__('catalog.product.form.check_in_offset_minutes.suffix'))
                        ->integer()
                        ->default(30)
                        ->minValue(0)
                        ->maxValue(65535),

                    Select::make('meeting_point_id')
                        ->label(__('catalog.product.form.meeting_point.label'))
                        ->helperText(__('catalog.product.form.meeting_point.help'))
                        ->options(static::portOptions(...))
                        ->searchable()
                        ->preload(),

                    // CAT-5: the flexible window is a whole-boat affordance.
                    // Hidden rather than merely refused, so an operator on a
                    // per-seat trip never fills in two times that the Action
                    // will silently clear.
                    Toggle::make('flexible_start')
                        ->label(__('catalog.product.form.flexible_start.label'))
                        ->helperText(__('catalog.product.form.flexible_start.help'))
                        ->live()
                        ->visible(static fn (Get $get): bool => static::modeOf($get)?->allowsFlexibleStart() === true),

                    TimePicker::make('earliest_start_time')
                        ->label(__('catalog.product.form.earliest_start_time.label'))
                        ->seconds(false)
                        ->native(false)
                        ->visible(static fn (Get $get): bool => (bool) $get('flexible_start')),

                    TimePicker::make('latest_start_time')
                        ->label(__('catalog.product.form.latest_start_time.label'))
                        ->seconds(false)
                        ->native(false)
                        ->visible(static fn (Get $get): bool => (bool) $get('flexible_start')),
                ])
                ->columns(2),

            Section::make(__('catalog.product.sections.content'))
                ->schema([
                    TranslatableInput::textarea(
                        'summary',
                        __('catalog.product.form.summary.label'),
                        __('catalog.product.form.summary.help'),
                        rows: 3,
                    ),

                    TranslatableInput::textarea(
                        'description',
                        __('catalog.product.form.description.label'),
                        __('catalog.product.form.description.help'),
                        rows: 8,
                    ),
                ]),

            Section::make(__('catalog.product.sections.capacity'))
                ->schema([
                    TextInput::make('max_pax')
                        ->label(__('catalog.product.form.max_pax.label'))
                        ->helperText(__('catalog.product.form.max_pax.help'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(65535),

                    // `min_pax` is the guaranteed-departure threshold and only
                    // means anything when seats are counted (CAT-5).
                    TextInput::make('min_pax')
                        ->label(__('catalog.product.form.min_pax.label'))
                        ->helperText(__('catalog.product.form.min_pax.help'))
                        ->integer()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(65535)
                        ->visible(static fn (Get $get): bool => static::modeOf($get)?->usesMinPax() === true),

                    TextInput::make('min_booking_pax')
                        ->label(__('catalog.product.form.min_booking_pax.label'))
                        ->helperText(__('catalog.product.form.min_booking_pax.help'))
                        ->integer()
                        ->default(1)
                        ->minValue(1)
                        ->maxValue(65535),
                ])
                ->columns(2),

            Section::make(__('catalog.product.sections.bands'))
                ->description(__('catalog.product.form.bands.help'))
                // A whole-boat charter sells the boat rather than passenger
                // categories, so the section is not offered there at all.
                ->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerSeat)
                ->schema([
                    Repeater::make('age_bands')
                        ->label(__('catalog.product.sections.bands'))
                        ->addActionLabel(__('catalog.product.form.bands.add'))
                        ->schema([
                            TextInput::make('code')
                                ->label(__('catalog.product.form.bands.code.label'))
                                ->helperText(__('catalog.product.form.bands.code.help'))
                                ->required()
                                ->maxLength(32),

                            TranslatableInput::text(
                                'label',
                                __('catalog.product.form.bands.label.label'),
                                __('catalog.product.form.bands.label.help'),
                                maxLength: 60,
                            ),

                            TextInput::make('min_age')
                                ->label(__('catalog.product.form.bands.min_age.label'))
                                ->integer()
                                ->required()
                                ->default(0)
                                ->minValue(0)
                                ->maxValue(120),

                            TextInput::make('max_age')
                                ->label(__('catalog.product.form.bands.max_age.label'))
                                ->helperText(__('catalog.product.form.bands.max_age.help'))
                                ->integer()
                                ->minValue(0)
                                ->maxValue(120),

                            Select::make('pricing_mode')
                                ->label(__('catalog.product.form.bands.pricing_mode.label'))
                                ->options(AgeBandPricing::options())
                                ->default(AgeBandPricing::Multiplier->value)
                                ->required()
                                ->live(),

                            TextInput::make('price_multiplier_bp')
                                ->label(__('catalog.product.form.bands.price_multiplier_bp.label'))
                                ->helperText(__('catalog.product.form.bands.price_multiplier_bp.help'))
                                ->integer()
                                ->minValue(0)
                                ->maxValue(65535)
                                ->visible(static fn (Get $get): bool => $get('pricing_mode') === AgeBandPricing::Multiplier->value),

                            Toggle::make('is_base')
                                ->label(__('catalog.product.form.bands.is_base.label')),

                            Toggle::make('counts_toward_capacity')
                                ->label(__('catalog.product.form.bands.counts_toward_capacity.label'))
                                ->helperText(__('catalog.product.form.bands.counts_toward_capacity.help'))
                                ->default(true),

                            Toggle::make('requires_adult')
                                ->label(__('catalog.product.form.bands.requires_adult.label')),
                        ])
                        ->itemLabel(fn (array $state): ?string => is_string($state['code'] ?? null) ? $state['code'] : null)
                        ->defaultItems(1)
                        ->columns(2)
                        ->columnSpanFull(),
                ]),

            Section::make(__('catalog.product.sections.policy'))
                ->schema([
                    Select::make('cancellation_policy_id')
                        ->label(__('catalog.product.form.cancellation_policy.label'))
                        ->helperText(__('catalog.product.form.cancellation_policy.help'))
                        ->options(static::cancellationPolicyOptions(...))
                        ->searchable()
                        ->preload(),

                    Select::make('vat_rate_id')
                        ->label(__('catalog.product.form.vat_rate.label'))
                        ->helperText(__('catalog.product.form.vat_rate.help'))
                        ->options(static::vatRateOptions(...))
                        // The account's own answer, from the setup guide (#51).
                        // Per-product stays the truth — a cruise is passenger
                        // transport and a barbecue extra is catering, and
                        // data-model §2.3 says so — but an operator answers the
                        // question once and overrides it where it differs,
                        // rather than choosing from nothing every time.
                        //
                        // `default()` and not a fill, so it applies to a new
                        // product only: an existing one whose rate was chosen
                        // deliberately, or deliberately left empty, keeps what
                        // it has.
                        ->default(static::defaultVatRateId(...))
                        ->searchable()
                        ->preload(),

                    Toggle::make('guest_details_required')
                        ->label(__('catalog.product.form.guest_details_required.label'))
                        ->helperText(__('catalog.product.form.guest_details_required.help'))
                        ->live(),

                    TextInput::make('guest_details_deadline_hours')
                        ->label(__('catalog.product.form.guest_details_deadline_hours.label'))
                        ->helperText(__('catalog.product.form.guest_details_deadline_hours.help'))
                        ->suffix(__('catalog.product.form.guest_details_deadline_hours.suffix'))
                        ->integer()
                        ->default(48)
                        ->minValue(0)
                        ->maxValue(65535)
                        ->visible(static fn (Get $get): bool => (bool) $get('guest_details_required')),
                ])
                ->columns(2),

            Section::make(__('catalog.product.sections.seo'))
                ->collapsed()
                ->schema([
                    TranslatableInput::text(
                        'meta_title',
                        __('catalog.product.form.meta_title.label'),
                        __('catalog.product.form.meta_title.help'),
                        required: false,
                        maxLength: 70,
                    ),

                    TranslatableInput::textarea(
                        'meta_description',
                        __('catalog.product.form.meta_description.label'),
                        __('catalog.product.form.meta_description.help'),
                        rows: 2,
                    ),
                ]),
        ];
    }

    /**
     * Greeklish, from the Greek title, and only into an empty slug.
     *
     * ## Why it never overwrites
     *
     * The slug is the trip's public address. Once a page has been shared, put in
     * an email or indexed, changing it breaks the link — so an operator who has
     * typed one, or who is editing a trip that has been live all season, must
     * never have it rewritten because they fixed a typo in the title.
     *
     * Auto-filling only into an empty field gives the useful half (nobody has to
     * invent `iliovasilema-stin-aigina` by hand) without the dangerous half.
     *
     * ## `Str::slug($title, '-', 'el')`
     *
     * Laravel's own Greek transliteration map, not a hand-written table:
     * «Ηλιοβασίλεμα στην Αίγινα» becomes `hliovasilema-stin-aighina`. A map of
     * our own would be one more thing to get wrong for ξ, ψ and the accented
     * vowels, and it would drift from the one the framework already ships.
     */
    public static function fillSlug(Get $get, Set $set, ?string $state): void
    {
        $slug = self::slugFor(
            is_string($get('slug')) ? $get('slug') : null,
            $state,
        );

        if ($slug !== null) {
            $set('slug', $slug);
        }
    }

    /**
     * The slug to write, or null to leave the field alone.
     *
     * Split out from the Filament closure so the rule can be tested without a
     * form: `Get` and `Set` are objects bound to a live component, and a rule
     * this consequential should not be reachable only through a browser.
     */
    public static function slugFor(?string $existing, ?string $title): ?string
    {
        if ($existing !== null && trim($existing) !== '') {
            return null;
        }

        $title = trim((string) $title);

        return $title === '' ? null : Str::slug($title, '-', 'el');
    }

    /**
     * One line per CAT-15 requirement, met or not.
     *
     * On an unsaved product half the checklist cannot be answered — a rate plan
     * and an age band both need a product id — so it says so plainly rather
     * than reporting six things missing that the operator is in the middle of
     * supplying.
     *
     * @return array<int, Component>
     */
    public static function checklistSchema(): array
    {
        return [
            Placeholder::make('publish_checklist')
                // No label: the section already carries one, and a second
                // heading above a single row of chips is a heading for nothing.
                ->hiddenLabel()
                ->content(static function (?Product $record): View {
                    $items = [];
                    $remaining = 0;

                    foreach (ProductPublishChecklist::requirements() as $requirement) {
                        $met = $record !== null
                            && $record->exists
                            && ProductPublishChecklist::satisfies($record, $requirement);

                        if (! $met) {
                            $remaining++;
                        }

                        $items[] = [
                            'met' => $met,
                            'label' => __("catalog.product.checklist.{$requirement}.label"),
                            'unmet' => __("catalog.product.checklist.{$requirement}.unmet"),
                        ];
                    }

                    return view('filament.app.product-checklist', [
                        'record' => $record,
                        'items' => $items,
                        'remaining' => $remaining,
                    ]);
                })
                ->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('catalog.product.table.title'))
                    ->sortable(query: self::sortByTitle(...))
                    ->searchable(query: self::searchByTitle(...)),

                TextColumn::make('vessel.name')
                    ->label(__('catalog.product.table.vessel'))
                    ->toggleable(),

                TextColumn::make('mode')
                    ->label(__('catalog.product.table.mode'))
                    ->badge()
                    ->formatStateUsing(static fn (BookingMode $state): string => $state->label()),

                TextColumn::make('status')
                    ->label(__('catalog.product.table.status'))
                    ->badge()
                    ->formatStateUsing(static fn (ProductStatus $state): string => $state->label()),

                TextColumn::make('max_pax')
                    ->label(__('catalog.product.table.max_pax')),

                // Derived (§1.9), so the list does not fan out across seasons,
                // plans and bands for every card. **Null renders as nothing**,
                // never as a zero: a `quote` product has no price by design and
                // a product with no resolvable plan is not sellable (PRC-5) —
                // "€0.00" on either is a free trip on a public page.
                TextColumn::make('price_from_cents')
                    ->label(__('catalog.product.table.price_from'))
                    ->formatStateUsing(static fn (?int $state): string => $state === null
                        ? ''
                        : __('catalog.product.table.price_from_value', [
                            'price' => MoneyFormatter::format($state),
                        ]))
                    ->alignEnd(),

                IconColumn::make('is_featured')
                    ->label(__('catalog.product.table.featured'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('catalog.product.table.status'))
                    ->options(ProductStatus::options()),
                TrashedFilter::make(),
            ])
            ->actions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function sortByTitle(Builder $query, string $direction): Builder
    {
        return $query->orderByTranslation('title', $direction);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function searchByTitle(Builder $query, string $search): Builder
    {
        return $query->whereTranslationMatches($search);
    }

    /** @return array<int, string> */
    public static function vesselOptions(): array
    {
        return Vessel::query()
            ->get()
            ->mapWithKeys(static fn (Vessel $vessel): array => [$vessel->getKey() => (string) $vessel->name])
            ->all();
    }

    /** @return array<int, string> */
    public static function portOptions(): array
    {
        return Port::query()
            ->get()
            ->mapWithKeys(static fn (Port $port): array => [$port->getKey() => (string) $port->name])
            ->all();
    }

    /** @return array<int, string> */
    public static function cancellationPolicyOptions(): array
    {
        return CancellationPolicy::query()
            ->get()
            ->mapWithKeys(static fn (CancellationPolicy $policy): array => [$policy->getKey() => (string) $policy->name])
            ->all();
    }

    /**
     * The rate a new product starts on, or null if the account has not said.
     *
     * Checked against the selectable list rather than returned as stored. A
     * rate withdrawn from the platform after an operator chose it would
     * otherwise be pre-filled as an id the select cannot render — the field
     * would look empty and submit a value, which is the worst of both.
     */
    public static function defaultVatRateId(): ?int
    {
        $id = Tenancy::check() ? Tenancy::current()?->default_vat_rate_id : null;

        if ($id === null) {
            return null;
        }

        return array_key_exists((int) $id, static::vatRateOptions()) ? (int) $id : null;
    }

    /** @return array<int, string> */
    public static function vatRateOptions(): array
    {
        return VatRate::query()
            ->selectable()
            ->get()
            // Code plus percentage: an operator picking a VAT rate is picking
            // a number, and «Μειωμένος» alone does not say which one.
            ->mapWithKeys(static fn (VatRate $rate): array => [
                $rate->getKey() => $rate->code . ' — ' . $rate->percentLabel(),
            ])
            ->all();
    }

    /** The booking mode currently chosen in the form. */
    public static function modeOf(Get $get): ?BookingMode
    {
        $mode = $get('mode');

        return $mode instanceof BookingMode ? $mode : BookingMode::tryFrom((string) $mode);
    }

    /** @return Builder<Product> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * The trip's prices, on the trip (#51 follow-up).
     *
     * Pricing one trip for two periods used to mean three screens and choosing
     * the trip from a dropdown twice. `product_id` cannot be wrong here,
     * because there is no such field — the trip is the record.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RatePlansRelationManager::class,
        ];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
