<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Catalog\Actions\SaveSeason;
use App\Filament\App\Resources\SeasonResource\Pages;
use App\Filament\Forms\TranslatableInput;
use App\Models\Season;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Pricing calendars on `/app` (spec CAT-9, PRC-3, PRC-4, SEC-3).
 *
 * Thin (CNV-5). The two rules that are not form affordances — no overlap within
 * a season, and no priority tie across them — live in
 * {@see SaveSeason}, because the importer needs both
 * and PRC-4 makes the second a refusal rather than advice.
 *
 * The table is sorted by priority descending, which is resolution order: an
 * operator looking at this list is looking at the order their prices are
 * decided in.
 */
class SeasonResource extends Resource
{
    protected static ?string $model = Season::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.catalogue');
    }

    public static function getNavigationLabel(): string
    {
        return __('pricing.season.nav');
    }

    public static function getModelLabel(): string
    {
        return __('pricing.season.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pricing.season.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('pricing.season.model.singular'))
                ->schema([
                    TranslatableInput::text(
                        'name',
                        __('pricing.season.form.name.label'),
                        __('pricing.season.form.name.help'),
                        maxLength: 80,
                    ),

                    TextInput::make('code')
                        ->label(__('pricing.season.form.code.label'))
                        ->helperText(__('pricing.season.form.code.help'))
                        ->maxLength(32),

                    TextInput::make('priority')
                        ->label(__('pricing.season.form.priority.label'))
                        ->helperText(__('pricing.season.form.priority.help'))
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue(65535)
                        ->default(0),

                    Toggle::make('is_active')
                        ->label(__('pricing.season.form.is_active.label'))
                        ->helperText(__('pricing.season.form.is_active.help'))
                        ->default(true),
                ])
                ->columns(2),

            Section::make(__('pricing.season.form.ranges.label'))
                ->description(__('pricing.season.form.ranges.help'))
                ->schema([
                    Repeater::make('dateRanges')
                        ->label(__('pricing.season.form.ranges.label'))
                        ->relationship()
                        ->addActionLabel(__('pricing.season.form.ranges.add'))
                        ->schema([
                            DatePicker::make('starts_on')
                                ->label(__('pricing.season.form.ranges.starts_on'))
                                ->required()
                                ->native(false),

                            DatePicker::make('ends_on')
                                ->label(__('pricing.season.form.ranges.ends_on'))
                                ->required()
                                ->native(false)
                                // Both bounds inclusive, so the same day is a
                                // valid one-day range — `afterOrEqual`, never
                                // `after`.
                                ->afterOrEqual('starts_on')
                                ->validationMessages([
                                    'after_or_equal' => __('pricing.season.validation.ends_before_starts'),
                                ]),
                        ])
                        ->itemLabel(fn (array $state): ?string => isset($state['starts_on'], $state['ends_on'])
                            ? $state['starts_on'] . ' – ' . $state['ends_on']
                            : null)
                        ->defaultItems(1)
                        ->columns(2)
                        ->columnSpanFull(),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('pricing.season.table.name'))
                    ->sortable(query: self::sortByName(...))
                    ->searchable(query: self::searchByName(...)),

                TextColumn::make('code')
                    ->label(__('pricing.season.table.code'))
                    ->toggleable(),

                TextColumn::make('priority')
                    ->label(__('pricing.season.table.priority'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('date_ranges_count')
                    ->label(__('pricing.season.table.ranges'))
                    ->counts('dateRanges'),

                IconColumn::make('is_active')
                    ->label(__('pricing.season.table.is_active'))
                    ->boolean(),
            ])
            // Resolution order: the list an operator reads is the order their
            // prices are decided in.
            ->defaultSort('priority', 'desc')
            ->filters([TrashedFilter::make()])
            ->actions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    /**
     * @param  Builder<Season>  $query
     * @return Builder<Season>
     */
    public static function sortByName(Builder $query, string $direction): Builder
    {
        return $query->orderByTranslation('name', $direction);
    }

    /**
     * @param  Builder<Season>  $query
     * @return Builder<Season>
     */
    public static function searchByName(Builder $query, string $search): Builder
    {
        return $query->whereTranslationMatches($search);
    }

    /** @return Builder<Season> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeasons::route('/'),
            'create' => Pages\CreateSeason::route('/create'),
            'edit' => Pages\EditSeason::route('/{record}/edit'),
        ];
    }
}
