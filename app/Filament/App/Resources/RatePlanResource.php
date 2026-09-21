<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Pricing\Actions\SaveRatePlan;
use App\Domain\Pricing\Support\PlanSummary;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Filament\App\Navigation\SiblingScreens;
use App\Filament\App\Resources\RatePlanResource\Pages;
use App\Filament\Forms\MoneyInput;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Support\Tenancy;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * What a trip costs, on `/app` (spec CAT-10, PRC-23, AVL-19, AVL-20, SEC-3).
 *
 * Thin (CNV-5): every rule lives in
 * {@see SaveRatePlan}, because the importer and
 * the API need all four of them and a form is not a place to keep an invariant.
 *
 * ## The form changes shape with the product's booking mode
 *
 * A whole-boat charter has one price; a per-seat trip has one price per age
 * band. Showing both at once and hoping the operator fills the right half is
 * how a plan ends up with a vessel price *and* band prices, which the Action
 * then refuses on submit — a rejection the operator could have been spared.
 * So the product select is `live()` and the two price sections are mutually
 * exclusive, driven by the same enum the Action validates against.
 *
 * ## Prices are integer cents in form state too
 *
 * Through {@see MoneyInput}, which parses decimal text with `brick/money`
 * rather than casting it to a float. CNV-1 says "not even transiently", and
 * form state is exactly where "transiently" hides.
 */
class RatePlanResource extends Resource
{
    protected static ?string $model = RatePlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.catalogue');
    }

    /** Stays highlighted on the screens it shares a tab bar with (Menu 1). */
    public static function getNavigationItems(): array
    {
        return SiblingScreens::highlight(static::class, parent::getNavigationItems());
    }

    public static function getNavigationLabel(): string
    {
        return __('panel.nav.prices');
    }

    public static function getModelLabel(): string
    {
        return __('pricing.rate_plan.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pricing.rate_plan.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('pricing.rate_plan.sections.identity'))
                ->schema([
                    Select::make('product_id')
                        ->label(__('pricing.rate_plan.form.product.label'))
                        ->helperText(__('pricing.rate_plan.form.product.help'))
                        ->options(static::productOptions(...))
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        // Changing the product changes which bands exist, so
                        // the price rows are rebuilt rather than left pointing
                        // at another product's bands.
                        ->afterStateUpdated(static function (Set $set, mixed $state): void {
                            $set('band_prices', static::bandPriceRows(
                                is_numeric($state) ? (int) $state : null,
                            ));
                        }),

                    Select::make('season_id')
                        ->label(__('pricing.rate_plan.form.season.label'))
                        ->helperText(__('pricing.rate_plan.form.season.help'))
                        ->options(static::seasonOptions(...))
                        // Empty is meaningful here — it is the product default
                        // — so the placeholder says so rather than reading
                        // like an unanswered question.
                        ->placeholder(__('pricing.rate_plan.form.season.default'))
                        ->searchable()
                        ->preload(),

                    TextInput::make('name')
                        ->label(__('pricing.rate_plan.form.name.label'))
                        ->helperText(__('pricing.rate_plan.form.name.help'))
                        ->maxLength(80),

                    Toggle::make('is_active')
                        ->label(__('pricing.rate_plan.form.is_active.label'))
                        ->helperText(__('pricing.rate_plan.form.is_active.help'))
                        ->default(true),
                ])
                ->columns(2),

            Section::make(__('pricing.rate_plan.sections.pricing'))
                ->schema([
                    MoneyInput::make(
                        'vessel_price_cents',
                        __('pricing.rate_plan.form.vessel_price_cents.label'),
                        __('pricing.rate_plan.form.vessel_price_cents.help'),
                    )->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerVessel),

                    MoneyInput::make(
                        'extra_hour_price_cents',
                        __('pricing.rate_plan.form.extra_hour_price_cents.label'),
                        __('pricing.rate_plan.form.extra_hour_price_cents.help'),
                    )->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerVessel),

                    // «Up to N people, +Y € for each extra» (2026-09-17), only
                    // where the platform switched it on.
                    TextInput::make('included_pax')
                        ->label(__('pricing.on_product.included_pax.label'))
                        ->helperText(__('pricing.on_product.included_pax.help'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(999)
                        ->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerVessel
                            && Tenancy::current()?->usesExtraPersonPricing() === true),

                    MoneyInput::make(
                        'extra_pax_price_cents',
                        __('pricing.on_product.extra_pax_price.label'),
                        __('pricing.on_product.extra_pax_price.help'),
                    )->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerVessel
                        && Tenancy::current()?->usesExtraPersonPricing() === true),

                    Repeater::make('band_prices')
                        ->label(__('pricing.rate_plan.form.prices.label'))
                        ->helperText(__('pricing.rate_plan.form.prices.help'))
                        ->schema([
                            Hidden::make('age_band_id'),

                            Placeholder::make('band_label')
                                ->label(__('pricing.rate_plan.form.prices.band'))
                                ->content(static fn (Get $get): string => (string) $get('band_label')),

                            MoneyInput::make(
                                'price_cents',
                                __('pricing.rate_plan.form.prices.price'),
                            ),
                        ])
                        // The rows are the product's age bands. There is
                        // nothing to add, remove or reorder — doing any of
                        // those would mean inventing a band.
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->columns(2)
                        ->columnSpanFull()
                        ->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerSeat),
                ]),

            Section::make(__('pricing.rate_plan.sections.deposit'))
                ->schema([
                    Select::make('deposit_type')
                        ->label(__('pricing.rate_plan.form.deposit_type.label'))
                        ->helperText(__('pricing.rate_plan.form.deposit_type.help'))
                        ->options(DepositType::options())
                        ->default(DepositType::None->value)
                        ->required()
                        ->live(),

                    TextInput::make('deposit_percent')
                        ->label(__('pricing.rate_plan.form.deposit_percent.label'))
                        ->helperText(__('pricing.rate_plan.form.deposit_percent.help'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(100)
                        ->required(static fn (Get $get): bool => $get('deposit_type') === DepositType::Percent->value)
                        ->visible(static fn (Get $get): bool => $get('deposit_type') === DepositType::Percent->value),

                    MoneyInput::make(
                        'deposit_fixed_cents',
                        __('pricing.rate_plan.form.deposit_fixed_cents.label'),
                        __('pricing.rate_plan.form.deposit_fixed_cents.help'),
                    )
                        ->required(static fn (Get $get): bool => $get('deposit_type') === DepositType::Fixed->value)
                        ->visible(static fn (Get $get): bool => $get('deposit_type') === DepositType::Fixed->value),
                ])
                ->columns(2),

            Section::make(__('pricing.rate_plan.sections.window'))
                ->schema([
                    TextInput::make('min_lead_time_hours')
                        ->label(__('pricing.rate_plan.form.min_lead_time_hours.label'))
                        ->helperText(__('pricing.rate_plan.form.min_lead_time_hours.help'))
                        ->suffix(__('pricing.rate_plan.form.min_lead_time_hours.suffix'))
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue(65535)
                        ->default(0),

                    TextInput::make('max_advance_days')
                        ->label(__('pricing.rate_plan.form.max_advance_days.label'))
                        ->helperText(__('pricing.rate_plan.form.max_advance_days.help'))
                        ->suffix(__('pricing.rate_plan.form.max_advance_days.suffix'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(65535),

                    TextInput::make('min_pax_override')
                        ->label(__('pricing.rate_plan.form.min_pax_override.label'))
                        ->helperText(__('pricing.rate_plan.form.min_pax_override.help'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(65535),
                ])
                ->columns(2),
        ];
    }

    /**
     * The catalogue's prices, grouped by trip (product owner, 2026-09-21,
     * direction Α of the «Σύνοψη εκδρομών» mockup).
     *
     * ## What was wrong with it
     *
     * It listed price lists **without a price in them**: trip, period, name,
     * deposit *type*, active. Every column except the number the screen is
     * named after. So *«πόσο κάνει το ηλιοβασίλεμα τον Ιούλιο;»* — the question
     * an operator has twenty times a day — could only be answered by opening
     * the trip, then its «Τιμές» tab, then reading a grid.
     *
     * ## The shape now
     *
     * One group per trip, whose heading carries the boat, the people, the
     * length of the day and how it is sold — the four things you would
     * otherwise open the trip to check. One row per period, in the order a
     * price is resolved: the default plan first, because it is the one that
     * applies when nothing else does, then the seasons.
     *
     * Every cell comes from {@see PlanSummary}, which reads and never resolves:
     * `QuotePrice` stays the only thing that decides what a party pays.
     *
     * ## Trips with no price at all
     *
     * Cannot appear here — a table of plans cannot show a trip that has none.
     * {@see ListRatePlans::getHeaderWidgets()} puts `UnsellableProducts` above
     * it, which already answers exactly that for published trips and already
     * explains, in its own docblock, why drafts are left out of it.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with([
                'product.vessel',
                'product.ageBands',
                'season.dateRanges',
                'prices',
            ]))
            ->groups([
                Group::make('product_id')
                    ->label(__('pricing.rate_plan.table.product'))
                    ->getTitleFromRecordUsing(static fn (RatePlan $record): string => (string) $record->product?->title)
                    ->getDescriptionFromRecordUsing(static fn (RatePlan $record): string => PlanSummary::productMeta($record->product))
                    ->titlePrefixedWithLabel(false),
            ])
            ->defaultGroup('product_id')
            ->columns([
                // `state()`, never `formatStateUsing()`: the default plan's
                // `season_id` is null, and Filament shows the placeholder for a
                // null state without ever calling the formatter — which left
                // the one row that matters most, «Όλες τις άλλες μέρες», as an
                // empty cell with no description under it.
                TextColumn::make('season_id')
                    ->label(__('pricing.rate_plan.table.season'))
                    ->state(static fn (RatePlan $record): string => PlanSummary::period($record))
                    // The other bands, or a charter's terms, under the period
                    // rather than in columns of their own: a trip may have six
                    // age bands and no table is six prices wide.
                    ->description(static fn (RatePlan $record): string => PlanSummary::detail($record))
                    ->width('34%')
                    ->wrap(),

                TextColumn::make('dates')
                    ->label(__('pricing.rate_plan.table.dates'))
                    ->state(static fn (RatePlan $record): string => PlanSummary::dates($record))
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray')
                    ->width('22%'),

                TextColumn::make('name')
                    ->label(__('pricing.rate_plan.table.name'))
                    ->toggleable(isToggledHiddenByDefault: true),

                // The point of the screen. A plan with no price yet says so in
                // words rather than showing nothing, because an empty cell on a
                // price list reads as a rendering fault, not as a finding.
                TextColumn::make('price')
                    ->label(__('pricing.rate_plan.table.price'))
                    ->state(static fn (RatePlan $record): string => PlanSummary::headline($record)
                        ?? __('pricing.rate_plan.table.no_price'))
                    ->color(static fn (RatePlan $record): ?string => PlanSummary::headline($record) === null ? 'danger' : null)
                    ->weight(FontWeight::SemiBold)
                    ->alignEnd(),

                TextColumn::make('deposit_type')
                    ->label(__('pricing.rate_plan.table.deposit'))
                    ->state(static fn (RatePlan $record): string => PlanSummary::deposit($record))
                    ->alignEnd(),

                IconColumn::make('is_active')
                    ->label(__('pricing.rate_plan.table.is_active'))
                    ->boolean(),
            ])
            // The default plan first within each trip, then the seasons: it is
            // the price that applies when no period matches, so the others read
            // as the exceptions to it.
            ->defaultSort('season_id')
            ->filters([TrashedFilter::make()])
            // Folded away: two labelled buttons on every row were taking a
            // fifth of a table whose whole point is the price column.
            ->actions([
                ActionGroup::make([EditAction::make(), DeleteAction::make(), RestoreAction::make()]),
            ]);
    }

    /** @return array<int, string> */
    public static function productOptions(): array
    {
        return Product::query()
            ->get()
            ->mapWithKeys(static fn (Product $product): array => [$product->getKey() => (string) $product->title])
            ->all();
    }

    /** @return array<int, string> */
    public static function seasonOptions(): array
    {
        return Season::query()
            ->get()
            ->mapWithKeys(static fn (Season $season): array => [$season->getKey() => (string) $season->name])
            ->all();
    }

    /**
     * One repeater row per age band of `$productId`, in the operator's order.
     *
     * `price_cents` comes from `$existing` when the plan already has a row for
     * that band, so re-rendering the form after a failed submit does not wipe
     * the prices the operator just typed.
     *
     * @param  array<int, int|null>  $existing  age band id => price in cents
     * @return list<array<string, mixed>>
     */
    public static function bandPriceRows(?int $productId, array $existing = []): array
    {
        if ($productId === null) {
            return [];
        }

        return AgeBand::query()
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (AgeBand $band): array => [
                'age_band_id' => $band->getKey(),
                // Localised, and suffixed for a band that must have a price so
                // the operator can see which rows they cannot leave blank.
                'band_label' => $band->label,
                'price_cents' => $existing[$band->getKey()] ?? null,
                'required' => $band->is_base || $band->pricing_mode === AgeBandPricing::Fixed,
            ])
            ->values()
            ->all();
    }

    /** The booking mode of the product currently selected in the form. */
    public static function modeOf(Get $get): ?BookingMode
    {
        $productId = $get('product_id');

        if (! is_numeric($productId)) {
            return null;
        }

        return Product::query()->find((int) $productId)?->mode;
    }

    /** @return Builder<RatePlan> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRatePlans::route('/'),
            'create' => Pages\CreateRatePlan::route('/create'),
            'edit' => Pages\EditRatePlan::route('/{record}/edit'),
        ];
    }
}
