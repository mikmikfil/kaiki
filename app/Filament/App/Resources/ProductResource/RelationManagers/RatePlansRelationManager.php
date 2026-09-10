<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\RelationManagers;

use App\Domain\Pricing\Actions\SaveRatePlan;
use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Filament\App\Resources\RatePlanResource;
use App\Filament\Forms\MoneyInput;
use App\Filament\Forms\TranslatableInput;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Season;
use App\Support\Format\MoneyFormatter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The trip's prices, on the trip (spec PRC-3, PRC-4, PRC-5, CAT-10).
 *
 * ## Why this exists when `RatePlanResource` already did
 *
 * It did, on its own screen, and pricing one trip for two periods meant: go to
 * Περίοδοι and build the periods, go to Τιμοκατάλογοι, press new, **find this
 * trip in a dropdown**, price it, press new again, find the same trip again.
 * Three screens and the trip chosen twice, to answer one question — *«150 τον
 * Ιούλιο, 100 τον Δεκέμβριο»*.
 *
 * The product owns its prices, so the prices live on the product. The
 * separate screen stays for an operator who wants to see every plan across the
 * fleet at once, but nobody has to start there any more.
 *
 * ## Nothing about the pricing rules moved
 *
 * {@see SaveRatePlan} is still the only writer, and still enforces all four of
 * its rules — one default plan per product, the plan matching the product's
 * mode, every underivable band having a price, and the deposit columns
 * agreeing with the deposit type. This class is a screen; it decides nothing.
 *
 * ## The product is the owner record, which is the whole simplification
 *
 * `product_id` is not a field here — it cannot be wrong, and it cannot be
 * forgotten. Everything that depended on it is read from the owner record
 * instead: the booking mode decides which price fields appear, and the age
 * bands are the ones this trip actually has.
 *
 * ## A period can be created without leaving
 *
 * `createOptionForm` on the season select, because "I need a summer price"
 * and "summer does not exist yet" arrive in the same minute, and sending
 * somebody to another screen mid-thought is how a price ends up on the wrong
 * period or not at all. It asks for the two things a period cannot work
 * without — a name and a date range — and leaves priority and code to the full
 * screen, where an operator with overlapping periods will already be.
 */
class RatePlansRelationManager extends RelationManager
{
    protected static string $relationship = 'ratePlans';

    protected static ?string $icon = 'heroicon-o-currency-euro';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pricing.on_product.title');
    }

    /** The trip this manager belongs to. */
    private function product(): Product
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $product;
    }

    private function mode(): BookingMode
    {
        return $this->product()->mode;
    }

    /* -----------------------------------------------------------------
     | The form
     ----------------------------------------------------------------- */

    public function form(Form $form): Form
    {
        $perSeat = $this->mode() === BookingMode::PerSeat;
        $perVessel = $this->mode() === BookingMode::PerVessel;

        return $form->schema([
            Select::make('season_id')
                ->label(__('pricing.on_product.season.label'))
                ->helperText(__('pricing.on_product.season.help'))
                ->options(RatePlanResource::seasonOptions(...))
                // Empty is an answer here, not a blank: it is the price for
                // every date no period covers. Saying so in the placeholder
                // stops it reading as an unfinished field.
                ->placeholder(__('pricing.on_product.season.default'))
                ->searchable()
                ->preload()
                ->createOptionForm([
                    TranslatableInput::text(
                        'name',
                        __('pricing.on_product.new_season.name'),
                        maxLength: 80,
                    ),
                    DatePicker::make('starts_on')
                        ->label(__('pricing.on_product.new_season.starts_on'))
                        ->required(),
                    DatePicker::make('ends_on')
                        ->label(__('pricing.on_product.new_season.ends_on'))
                        ->required()
                        ->afterOrEqual('starts_on'),
                ])
                ->createOptionUsing(fn (array $data): int => $this->createSeason($data))
                ->columnSpanFull(),

            /*
             * Per-seat: one price per age band.
             *
             * The rows are the trip's own bands and cannot be added to or
             * removed — doing either would mean inventing a passenger category
             * from a price screen. A band whose price may be left blank derives
             * it from the base band's; the ones that cannot say so in their
             * label.
             */
            Repeater::make('band_prices')
                ->label(__('pricing.on_product.prices.label'))
                ->helperText(__('pricing.on_product.prices.help'))
                ->schema([
                    Hidden::make('age_band_id'),

                    Placeholder::make('band_label')
                        ->label(__('pricing.on_product.prices.band'))
                        ->content(static fn (Get $get): string => (string) $get('band_label')),

                    MoneyInput::make('price_cents', __('pricing.on_product.prices.price')),
                ])
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->columns(2)
                ->columnSpanFull()
                ->visible($perSeat),

            MoneyInput::make(
                'vessel_price_cents',
                __('pricing.on_product.vessel_price.label'),
                __('pricing.on_product.vessel_price.help'),
            )->visible($perVessel),

            MoneyInput::make(
                'extra_hour_price_cents',
                __('pricing.on_product.extra_hour.label'),
                __('pricing.on_product.extra_hour.help'),
            )->visible($perVessel),

            /*
             * Everything an operator does not answer on a normal day.
             *
             * Collapsed rather than moved away: a deposit that differs by
             * season is a real thing, and so is a booking window, but neither
             * belongs in front of somebody typing «150».
             */
            Section::make(__('pricing.on_product.more'))
                ->collapsed()
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->label(__('pricing.on_product.name.label'))
                        ->helperText(__('pricing.on_product.name.help'))
                        ->maxLength(80),

                    Toggle::make('is_active')
                        ->label(__('pricing.on_product.is_active.label'))
                        ->helperText(__('pricing.on_product.is_active.help'))
                        ->default(true),

                    Select::make('deposit_type')
                        ->label(__('pricing.on_product.deposit_type.label'))
                        ->options(DepositType::options())
                        ->default(DepositType::None->value)
                        ->live(),

                    TextInput::make('deposit_percent')
                        ->label(__('pricing.on_product.deposit_percent.label'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->suffix('%')
                        ->visible(static fn (Get $get): bool => $get('deposit_type') === DepositType::Percent->value),

                    MoneyInput::make(
                        'deposit_fixed_cents',
                        __('pricing.on_product.deposit_fixed.label'),
                    )->visible(static fn (Get $get): bool => $get('deposit_type') === DepositType::Fixed->value),

                    TextInput::make('min_lead_time_hours')
                        ->label(__('pricing.on_product.min_lead_time.label'))
                        ->helperText(__('pricing.on_product.min_lead_time.help'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(65535),

                    TextInput::make('max_advance_days')
                        ->label(__('pricing.on_product.max_advance.label'))
                        ->helperText(__('pricing.on_product.max_advance.help'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(65535),
                ])
                ->columns(2),
        ])->columns(1);
    }

    /* -----------------------------------------------------------------
     | The table
     ----------------------------------------------------------------- */

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('season_id')
                    ->label(__('pricing.on_product.table.season'))
                    ->formatStateUsing(fn (?int $state): string => $this->seasonLabel($state))
                    ->weight('semibold'),

                TextColumn::make('id')
                    ->label(__('pricing.on_product.table.price'))
                    // The headline figure, which is the base band's price on a
                    // per-seat trip and the boat's on a charter. An operator
                    // scanning this table is checking «150 and 100», not the
                    // whole band table.
                    ->formatStateUsing(fn (RatePlan $record): string => $this->headlinePrice($record)),

                TextColumn::make('deposit_type')
                    ->label(__('pricing.on_product.table.deposit'))
                    // The enum's own translated label, not its stored value:
                    // «percent» is a column, «Ποσοστό» is what an operator
                    // chose. `HasTranslatedLabel` is where every other screen
                    // reads it from.
                    ->formatStateUsing(static fn (DepositType $state): string => $state->label())
                    ->badge(),

                IconColumn::make('is_active')
                    ->label(__('pricing.on_product.table.is_active'))
                    ->boolean(),
            ])
            // The default plan first, then the seasons. It is the one that
            // applies when nothing else does, so it reads as the baseline the
            // others are exceptions to.
            ->defaultSort('season_id')
            ->emptyStateHeading(__('pricing.on_product.empty.heading'))
            ->emptyStateDescription(__('pricing.on_product.empty.body'))
            ->headerActions([
                CreateAction::make()
                    ->label(__('pricing.on_product.add'))
                    ->modalHeading(__('pricing.on_product.add'))
                    // A new plan opens with one row per band of *this* trip.
                    // Without this the repeater renders empty and the operator
                    // is looking at a price form with nowhere to type a price.
                    // `fillForm` **replaces** the defaults the fields declare,
                    // so every default this form relies on has to be repeated
                    // here. `min_lead_time_hours` is `NOT NULL` and learning
                    // that from a constraint violation is what the first
                    // operator to press this button would have done.
                    ->fillForm(fn (): array => [
                        'band_prices' => RatePlanResource::bandPriceRows($this->product()->getKey()),
                        'is_active' => true,
                        'deposit_type' => DepositType::None->value,
                        'min_lead_time_hours' => 0,
                    ])
                    ->using(fn (array $data): Model => $this->persist(new RatePlan, $data)),
            ])
            ->actions([
                EditAction::make()
                    ->modalHeading(__('pricing.on_product.edit'))
                    ->mutateRecordDataUsing(fn (array $data, RatePlan $record): array => $this->fillPrices($data, $record))
                    ->using(fn (RatePlan $record, array $data): Model => $this->persist($record, $data)),

                DeleteAction::make(),
            ]);
    }

    /* -----------------------------------------------------------------
     | Reading and writing
     ----------------------------------------------------------------- */

    /**
     * Hand the form's state to the domain Action, band prices and all.
     *
     * The repeater is deliberately not a `relationship()`: the coverage rule —
     * every fixed band and the base band must have a price — is checked against
     * the set the save would produce, and it has to happen in the same
     * transaction as the rows. Letting Filament write them separately would
     * split the check from what it checks.
     *
     * @param  array<string, mixed>  $data
     */
    private function persist(RatePlan $record, array $data): RatePlan
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $data['band_prices'] ?? [];
        unset($data['band_prices']);

        $bandPrices = [];

        foreach ($rows as $row) {
            $price = $row['price_cents'] ?? null;

            // Blank is "no price for this band", not zero. Whether that is
            // allowed is the Action's decision, from the band's pricing mode.
            if ($price === null || $price === '') {
                continue;
            }

            $bandPrices[(int) $row['age_band_id']] = (int) $price;
        }

        foreach (['season_id', 'name', 'deposit_percent', 'deposit_fixed_cents', 'max_advance_days'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        // `NOT NULL` with a column default, and blank means *no minimum
        // notice* rather than *unknown*. Belt as well as braces: the create
        // form seeds it too, and a field added to this form later should not
        // be able to reintroduce the constraint violation.
        if (($data['min_lead_time_hours'] ?? null) === null || ($data['min_lead_time_hours'] ?? '') === '') {
            $data['min_lead_time_hours'] = 0;
        }

        try {
            return app(SaveRatePlan::class)($record, $this->product(), $data, $bandPrices);
        } catch (ValidationException $exception) {
            throw $this->attachToForm($exception);
        }
    }

    /**
     * Re-key the Action's errors onto the modal's fields.
     *
     * The Action reports on `season_id`, because that is the column's name
     * everywhere else. Livewire looks for the form's own state path, and
     * without it the message renders as a floating banner while the field that
     * caused it stays unmarked — the operator reads *"this trip already has a
     * default price"* and has to guess which control to change.
     */
    private function attachToForm(ValidationException $exception): ValidationException
    {
        $prefix = $this->getMountedTableActionForm()?->getStatePath();

        if (! is_string($prefix) || $prefix === '') {
            return $exception;
        }

        $messages = [];

        foreach ($exception->errors() as $key => $bag) {
            $messages["{$prefix}.{$key}"] = $bag;
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * Rebuild the price rows from the trip's bands when the modal opens.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillPrices(array $data, RatePlan $record): array
    {
        /** @var array<int, int|null> $existing */
        $existing = $record->prices()->pluck('price_cents', 'age_band_id')->all();

        $data['band_prices'] = RatePlanResource::bandPriceRows($record->product_id, $existing);

        return $data;
    }

    /**
     * A period and its first date range, in one transaction.
     *
     * Half a period — a name with no dates — matches nothing and prices
     * nothing, and would sit in the list looking like it works.
     *
     * @param  array<string, mixed>  $data
     */
    private function createSeason(array $data): int
    {
        return DB::transaction(function () use ($data): int {
            $season = Season::query()->create([
                'name' => $data['name'] ?? [],
                'priority' => 0,
                'is_active' => true,
            ]);

            $season->dateRanges()->create([
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
            ]);

            return (int) $season->getKey();
        });
    }

    private function seasonLabel(?int $seasonId): string
    {
        if ($seasonId === null) {
            return __('pricing.on_product.season.default');
        }

        $season = Season::query()->find($seasonId);

        return $season === null
            ? __('pricing.on_product.season.default')
            : (string) $season->name;
    }

    /**
     * The one figure worth putting in a table row.
     *
     * The base band's price on a per-seat trip, the boat's on a charter. A
     * dash where there is none rather than a zero — «0,00 €» reads as free.
     */
    private function headlinePrice(RatePlan $plan): string
    {
        if ($this->mode() === BookingMode::PerVessel) {
            return $plan->vessel_price_cents === null
                ? '—'
                : self::money((int) $plan->vessel_price_cents);
        }

        $baseBandId = AgeBand::query()
            ->where('product_id', $plan->product_id)
            ->where('is_base', true)
            ->value('id');

        if ($baseBandId === null) {
            return '—';
        }

        $cents = $plan->prices()->where('age_band_id', $baseBandId)->value('price_cents');

        return $cents === null ? '—' : self::money((int) $cents);
    }

    /** The tenant's own currency and the operator's own locale, in one place. */
    private static function money(int $cents): string
    {
        return MoneyFormatter::format($cents, null, MoneyFormatter::currency());
    }
}
