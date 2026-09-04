<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Availability\Support\WeekdayMask;
use App\Domain\Catalog\Actions\SaveScheduleRule;
use App\Enums\BookingMode;
use App\Filament\App\Resources\ScheduleRuleResource\Pages;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\Vessel;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * When a trip sails, on `/app` (spec CAT-14, AVL-52, AVL-55, SEC-3, TEN-8).
 *
 * Thin (CNV-5): {@see SaveScheduleRule} holds the
 * three refusals, because the importer will need all three.
 *
 * ## Seven checkboxes, not a number
 *
 * The column is a bitmask and the operator is not. `weekday_mask = 62` is a
 * sensible way to store "Tuesday to Saturday" and an impossible way to edit it,
 * so the form works in ISO weekday numbers and
 * {@see WeekdayMask} does the arithmetic in
 * the one place that is allowed to.
 *
 * ## The preview shows dates, not instants
 *
 * AVL-16 allows exactly one class to convert a local time to a UTC departure
 * instant, and that class is #26's. So this preview answers "which days" — the
 * question an operator checking a weekday mask is actually asking — and leaves
 * the conversion to the generator rather than writing a second one that would
 * drift across a DST boundary.
 */
class ScheduleRuleResource extends Resource
{
    protected static ?string $model = ScheduleRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.catalogue');
    }

    public static function getNavigationLabel(): string
    {
        return __('availability.schedule_rule.nav');
    }

    public static function getModelLabel(): string
    {
        return __('availability.schedule_rule.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('availability.schedule_rule.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('availability.schedule_rule.sections.what'))
                ->schema([
                    Select::make('product_id')
                        ->label(__('availability.schedule_rule.form.product.label'))
                        ->helperText(__('availability.schedule_rule.form.product.help'))
                        // Only per-seat products are offered, because CAT-14
                        // refuses the rest — refusing on submit what the form
                        // offered is a worse conversation than not offering it.
                        ->options(static::productOptions(...))
                        ->required()
                        ->searchable()
                        ->preload(),

                    Select::make('vessel_id')
                        ->label(__('availability.schedule_rule.form.vessel.label'))
                        ->helperText(__('availability.schedule_rule.form.vessel.help'))
                        ->options(static::vesselOptions(...))
                        ->searchable()
                        ->preload(),

                    Toggle::make('is_active')
                        ->label(__('availability.schedule_rule.form.is_active.label'))
                        ->helperText(__('availability.schedule_rule.form.is_active.help'))
                        ->default(true),
                ])
                ->columns(2),

            Section::make(__('availability.schedule_rule.sections.when'))
                ->schema([
                    CheckboxList::make('weekdays')
                        ->label(__('availability.schedule_rule.form.weekday_mask.label'))
                        ->helperText(__('availability.schedule_rule.form.weekday_mask.help'))
                        ->options(static::dayOptions())
                        ->columns(4)
                        ->required()
                        ->live()
                        ->columnSpanFull(),

                    TimePicker::make('start_time')
                        ->label(__('availability.schedule_rule.form.start_time.label'))
                        ->helperText(__('availability.schedule_rule.form.start_time.help'))
                        ->seconds(false)
                        ->native(false)
                        ->required(),
                ])
                ->columns(2),

            Section::make(__('availability.schedule_rule.sections.window'))
                ->schema([
                    DatePicker::make('valid_from')
                        ->label(__('availability.schedule_rule.form.valid_from.label'))
                        ->native(false)
                        ->required()
                        ->live(),

                    DatePicker::make('valid_until')
                        ->label(__('availability.schedule_rule.form.valid_until.label'))
                        ->helperText(__('availability.schedule_rule.form.valid_until.help'))
                        ->native(false)
                        ->live()
                        // Both bounds inclusive, so a one-day window is valid.
                        ->afterOrEqual('valid_from')
                        ->validationMessages([
                            'after_or_equal' => __('availability.schedule_rule.validation.inverted_window'),
                        ]),

                    TextInput::make('generate_days_ahead')
                        ->label(__('availability.schedule_rule.form.generate_days_ahead.label'))
                        ->helperText(__('availability.schedule_rule.form.generate_days_ahead.help'))
                        ->suffix(__('availability.schedule_rule.form.generate_days_ahead.suffix'))
                        ->integer()
                        ->required()
                        ->default(180)
                        ->minValue(1)
                        ->maxValue(730),
                ])
                ->columns(2),

            Section::make(__('availability.schedule_rule.sections.capacity'))
                ->schema([
                    TextInput::make('capacity_override')
                        ->label(__('availability.schedule_rule.form.capacity_override.label'))
                        ->helperText(__('availability.schedule_rule.form.capacity_override.help'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(65535),
                ]),

            Section::make(__('availability.schedule_rule.sections.preview'))
                ->description(__('availability.schedule_rule.preview.help'))
                ->schema([
                    Placeholder::make('next_dates')
                        ->label(__('availability.schedule_rule.sections.preview'))
                        ->content(static fn (Get $get): string => static::previewLine($get)),
                ]),
        ];
    }

    /**
     * The next ten local dates the form's current settings would produce.
     *
     * Computed from form state rather than from the saved row, so an operator
     * ticking Wednesday sees Wednesday appear before they commit — which is the
     * entire point of a preview.
     */
    public static function previewLine(Get $get): string
    {
        /** @var array<int, int|string> $days */
        $days = (array) ($get('weekdays') ?? []);
        $from = $get('valid_from');

        if ($days === [] || ! is_string($from) || $from === '') {
            return __('availability.schedule_rule.preview.unsaved');
        }

        $until = $get('valid_until');

        $dates = WeekdayMask::nextDates(
            WeekdayMask::fromDays($days),
            Carbon::parse($from)->max(Carbon::today()),
            10,
            is_string($until) && $until !== '' ? Carbon::parse($until) : null,
        );

        if ($dates === []) {
            return __('availability.schedule_rule.preview.none');
        }

        return implode(', ', array_map(
            static fn (Carbon $date): string => $date->isoFormat('ddd D MMM YYYY'),
            $dates,
        ));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product_id')
                    ->label(__('availability.schedule_rule.table.product'))
                    ->formatStateUsing(static fn (ScheduleRule $record): string => (string) $record->product?->title),

                TextColumn::make('weekday_mask')
                    ->label(__('availability.schedule_rule.table.days'))
                    ->formatStateUsing(static fn (int $state): string => static::daysLabel($state)),

                TextColumn::make('start_time')
                    ->label(__('availability.schedule_rule.table.start_time')),

                TextColumn::make('valid_from')
                    ->label(__('availability.schedule_rule.table.window'))
                    ->formatStateUsing(static fn (ScheduleRule $record): string => $record->valid_from->toDateString()
                        . ' – '
                        . ($record->valid_until?->toDateString() ?? __('availability.schedule_rule.table.open_ended'))),

                TextColumn::make('capacity_override')
                    ->label(__('availability.schedule_rule.table.capacity'))
                    ->formatStateUsing(static fn (ScheduleRule $record): string => (string) $record->effectiveCapacity()),

                IconColumn::make('is_active')
                    ->label(__('availability.schedule_rule.table.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('valid_from')
            ->actions([EditAction::make(), DeleteAction::make()]);
    }

    /** "Daily", or the day names — never the number the column stores. */
    public static function daysLabel(int $mask): string
    {
        if ($mask === WeekdayMask::DAILY) {
            return __('availability.schedule_rule.table.daily');
        }

        return implode(', ', array_map(
            static fn (int $day): string => (string) __("availability.schedule_rule.days.{$day}"),
            WeekdayMask::toDays($mask),
        ));
    }

    /** @return array<int, string> */
    public static function dayOptions(): array
    {
        $options = [];

        foreach (WeekdayMask::ISO_DAYS as $day) {
            $options[$day] = (string) __("availability.schedule_rule.days.{$day}");
        }

        return $options;
    }

    /** @return array<int, string> */
    public static function productOptions(): array
    {
        return Product::query()
            ->ofMode(BookingMode::PerSeat)
            ->get()
            ->mapWithKeys(static fn (Product $product): array => [$product->getKey() => (string) $product->title])
            ->all();
    }

    /** @return array<int, string> */
    public static function vesselOptions(): array
    {
        return Vessel::query()
            ->get()
            ->mapWithKeys(static fn (Vessel $vessel): array => [$vessel->getKey() => (string) $vessel->name])
            ->all();
    }

    /** @return Builder<ScheduleRule> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScheduleRules::route('/'),
            'create' => Pages\CreateScheduleRule::route('/create'),
            'edit' => Pages\EditScheduleRule::route('/{record}/edit'),
        ];
    }
}
