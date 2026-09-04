<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Catalog\Actions\SaveCancellationPolicy;
use App\Filament\App\Resources\CancellationPolicyResource\Pages;
use App\Filament\Forms\TranslatableInput;
use App\Models\CancellationPolicy;
use Filament\Forms\Components\Component;
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
 * Cancellation terms on `/app` (spec CAT-13, CXL-3, SEC-3).
 *
 * Thin (CNV-5): the two things that are not form affordances — one default per
 * tenant, and replacing the ladder rather than merging it — live in
 * {@see SaveCancellationPolicy}, because the
 * importer needs both and neither is a rule a form should own.
 *
 * ## The ladder is shown as sentences, not as a grid of numbers
 *
 * "Cancel 15 days ahead → 100% back" is what an operator is actually deciding.
 * A repeater of two unlabelled integers is a form somebody fills in wrongly and
 * only discovers when a guest is refunded the wrong amount — and by then the
 * snapshot has frozen it onto the booking.
 *
 * ## Editing a policy never changes an existing booking
 *
 * Worth knowing while looking at this screen: CXL-1 freezes the policy onto the
 * booking at payment time, so nothing here can rewrite money already owed. That
 * is why the form is free to be fully editable and why deleting a policy is a
 * soft delete rather than a refusal.
 */
class CancellationPolicyResource extends Resource
{
    protected static ?string $model = CancellationPolicy::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.catalogue');
    }

    public static function getNavigationLabel(): string
    {
        return __('pricing.cancellation.nav');
    }

    public static function getModelLabel(): string
    {
        return __('pricing.cancellation.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pricing.cancellation.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('pricing.cancellation.sections.identity'))
                ->schema([
                    TranslatableInput::text(
                        'name',
                        __('pricing.cancellation.form.name.label'),
                        __('pricing.cancellation.form.name.help'),
                        maxLength: 80,
                    ),

                    TranslatableInput::textarea(
                        'summary',
                        __('pricing.cancellation.form.summary.label'),
                        __('pricing.cancellation.form.summary.help'),
                        rows: 2,
                    ),

                    Toggle::make('is_default')
                        ->label(__('pricing.cancellation.form.is_default.label'))
                        ->helperText(__('pricing.cancellation.form.is_default.help')),
                ]),

            Section::make(__('pricing.cancellation.sections.ladder'))
                ->description(__('pricing.cancellation.form.tiers.help'))
                ->schema([
                    TextInput::make('free_cancellation_hours')
                        ->label(__('pricing.cancellation.form.free_cancellation_hours.label'))
                        ->helperText(__('pricing.cancellation.form.free_cancellation_hours.help'))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(65535)
                        ->suffix(__('pricing.cancellation.form.free_cancellation_hours.suffix')),

                    Repeater::make('tiers')
                        ->label(__('pricing.cancellation.form.tiers.label'))
                        ->relationship()
                        ->addActionLabel(__('pricing.cancellation.form.tiers.add'))
                        ->schema([
                            TextInput::make('days_before')
                                ->label(__('pricing.cancellation.form.tiers.days_before'))
                                ->integer()
                                ->required()
                                ->minValue(0)
                                ->maxValue(65535),

                            TextInput::make('refund_percent')
                                ->label(__('pricing.cancellation.form.tiers.refund_percent'))
                                ->integer()
                                ->required()
                                ->minValue(0)
                                // The column is a tinyint and holds 255, so the
                                // 0-100 range is enforced here — a column that
                                // cannot express a rejected value turns a typo
                                // into a truncation.
                                ->maxValue(100)
                                ->suffix('%')
                                ->validationMessages([
                                    'max' => __('pricing.cancellation.validation.refund_percent_range'),
                                    'min' => __('pricing.cancellation.validation.refund_percent_range'),
                                ]),
                        ])
                        // The rung read back as the sentence it means, so a
                        // collapsed ladder is still legible.
                        ->itemLabel(fn (array $state): ?string => isset($state['days_before'], $state['refund_percent'])
                            ? __('pricing.cancellation.form.tiers.preview', [
                                'days' => $state['days_before'],
                                'percent' => $state['refund_percent'],
                            ])
                            : null)
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columns(2)
                        ->columnSpanFull(),
                ]),

            Section::make(__('pricing.cancellation.sections.special'))
                ->schema([
                    TextInput::make('weather_refund_percent')
                        ->label(__('pricing.cancellation.form.weather_refund_percent.label'))
                        ->helperText(__('pricing.cancellation.form.weather_refund_percent.help'))
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(100)
                        ->suffix('%'),

                    TextInput::make('no_show_refund_percent')
                        ->label(__('pricing.cancellation.form.no_show_refund_percent.label'))
                        ->helperText(__('pricing.cancellation.form.no_show_refund_percent.help'))
                        ->integer()
                        ->required()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(0)
                        ->suffix('%'),

                    TextInput::make('force_majeure_voucher_months')
                        ->label(__('pricing.cancellation.form.force_majeure_voucher_months.label'))
                        ->helperText(__('pricing.cancellation.form.force_majeure_voucher_months.help'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(255)
                        ->default(18)
                        ->suffix(__('pricing.cancellation.form.force_majeure_voucher_months.suffix')),
                ])
                ->columns(3)
                ->collapsed(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('pricing.cancellation.table.name'))
                    // Through the companion column from #15: MySQL folds Greek
                    // tonos and SQLite does not, so a raw sort returns a
                    // different order on the two engines.
                    ->sortable(query: self::sortByName(...))
                    ->searchable(query: self::searchByName(...)),

                TextColumn::make('free_cancellation_hours')
                    ->label(__('pricing.cancellation.table.free_cancellation'))
                    ->placeholder(__('pricing.cancellation.table.none'))
                    ->suffix(' ' . __('pricing.cancellation.form.free_cancellation_hours.suffix'))
                    ->sortable(),

                TextColumn::make('tiers_count')
                    ->label(__('pricing.cancellation.table.tiers'))
                    ->counts('tiers'),

                IconColumn::make('is_default')
                    ->label(__('pricing.cancellation.table.is_default'))
                    ->boolean(),
            ])
            ->defaultSort('is_default', 'desc')
            ->filters([TrashedFilter::make()])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    /**
     * @param  Builder<CancellationPolicy>  $query
     * @return Builder<CancellationPolicy>
     */
    public static function sortByName(Builder $query, string $direction): Builder
    {
        return $query->orderByTranslation('name', $direction);
    }

    /**
     * @param  Builder<CancellationPolicy>  $query
     * @return Builder<CancellationPolicy>
     */
    public static function searchByName(Builder $query, string $search): Builder
    {
        return $query->whereTranslationMatches($search);
    }

    /** @return Builder<CancellationPolicy> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCancellationPolicies::route('/'),
            'create' => Pages\CreateCancellationPolicy::route('/create'),
            'edit' => Pages\EditCancellationPolicy::route('/{record}/edit'),
        ];
    }
}
