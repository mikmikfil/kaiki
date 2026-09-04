<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Availability\Actions\CreateVesselBlock;
use App\Enums\BlockReason;
use App\Filament\App\Resources\VesselBlockResource\Pages;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Taking a boat out of service, on `/app` (spec AVL-3, AVL-4, TEN-8, SEC-3).
 *
 * Thin (CNV-5): {@see CreateVesselBlock} owns
 * the window arithmetic, because an all-day block is not 24 hours on two days
 * a year and the iCal importer will need the same answer in M5.
 *
 * ## Only two of the four reasons are the operator's
 *
 * A `private_booking` block belongs to a booking and disappears when that
 * booking is cancelled; deleting it here would free a boat somebody has paid
 * for. An `external_ical` block belongs to a feed and comes back on the next
 * poll, so removing it is a promise the sync will break within fifteen minutes.
 * Both are therefore filtered out of the create form and refused a delete
 * button — the operator can see them and cannot pull them out from under the
 * thing that owns them.
 *
 * ## The date and time pickers do not convert
 *
 * `->timezone('UTC')`, for the same reason as the departure form: the panel
 * registers a tenant display timezone (CNV-2) and these fields carry wall-clock
 * values that the Action resolves itself. Letting the picker convert them
 * shifted an operator's 12:00 by three hours before the Action ever saw it.
 */
class VesselBlockResource extends Resource
{
    protected static ?string $model = VesselBlock::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 45;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.catalogue');
    }

    public static function getNavigationLabel(): string
    {
        return __('availability.block.nav');
    }

    public static function getModelLabel(): string
    {
        return __('availability.block.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('availability.block.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('availability.block.sections.what'))
                ->schema([
                    Select::make('vessel_id')
                        ->label(__('availability.block.form.vessel.label'))
                        ->options(static::vesselOptions(...))
                        ->required()
                        ->searchable()
                        ->preload(),

                    Select::make('reason')
                        ->label(__('availability.block.form.reason.label'))
                        ->helperText(__('availability.block.form.reason.help'))
                        // Only the two an operator owns. The other two are
                        // created by a booking and by a feed, and offering them
                        // here would invite a row nothing maintains.
                        ->options(static::operatorReasonOptions())
                        ->default(BlockReason::Maintenance->value)
                        ->required(),

                    TextInput::make('title')
                        ->label(__('availability.block.form.title.label'))
                        ->maxLength(190),
                ])
                ->columns(2),

            Section::make(__('availability.block.sections.when'))
                ->schema([
                    Toggle::make('is_all_day')
                        ->label(__('availability.block.form.is_all_day.label'))
                        ->helperText(__('availability.block.form.is_all_day.help'))
                        ->live()
                        ->columnSpanFull(),

                    DatePicker::make('local_date')
                        ->label(__('availability.block.form.local_date.label'))
                        ->timezone('UTC')
                        ->native(false)
                        ->required(),

                    DatePicker::make('local_end_date')
                        ->label(__('availability.block.form.local_end_date.label'))
                        ->helperText(__('availability.block.form.local_end_date.help'))
                        ->timezone('UTC')
                        ->native(false)
                        ->afterOrEqual('local_date'),

                    TimePicker::make('start_time')
                        ->label(__('availability.block.form.start_time.label'))
                        ->timezone('UTC')
                        ->seconds(false)
                        ->native(false)
                        ->required(static fn (Get $get): bool => ! $get('is_all_day'))
                        ->visible(static fn (Get $get): bool => ! $get('is_all_day')),

                    TimePicker::make('end_time')
                        ->label(__('availability.block.form.end_time.label'))
                        ->timezone('UTC')
                        ->seconds(false)
                        ->native(false)
                        ->required(static fn (Get $get): bool => ! $get('is_all_day'))
                        ->visible(static fn (Get $get): bool => ! $get('is_all_day')),

                    Textarea::make('notes')
                        ->label(__('availability.block.form.notes.label'))
                        ->rows(3)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('local_date')
                    ->label(__('availability.block.table.local_date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('local_end_date')
                    ->label(__('availability.block.table.local_end_date'))
                    ->date(),

                TextColumn::make('vessel_id')
                    ->label(__('availability.block.table.vessel'))
                    ->formatStateUsing(static fn (VesselBlock $record): string => (string) $record->vessel?->name),

                TextColumn::make('reason')
                    ->label(__('availability.block.table.reason'))
                    ->badge()
                    ->formatStateUsing(static fn (BlockReason $state): string => $state->label()),

                TextColumn::make('title')
                    ->label(__('availability.block.table.title'))
                    ->toggleable(),

                IconColumn::make('is_all_day')
                    ->label(__('availability.block.table.all_day'))
                    ->boolean(),
            ])
            ->defaultSort('starts_at_utc')
            ->filters([
                SelectFilter::make('reason')
                    ->label(__('availability.block.table.reason'))
                    ->options(BlockReason::options()),
            ])
            ->actions([
                // No edit: a block is a window, and changing one is clearer as
                // delete-and-recreate than as a partial update whose halves
                // have to stay consistent.
                DeleteAction::make()
                    // Only the two an operator owns. Removing the others would
                    // free a boat somebody paid for, or make a promise the next
                    // sync breaks.
                    ->visible(static fn (VesselBlock $record): bool => $record->reason->isOperatorOwned()),
            ]);
    }

    /** @return array<int, string> */
    public static function vesselOptions(): array
    {
        return Vessel::query()
            ->get()
            ->mapWithKeys(static fn (Vessel $vessel): array => [$vessel->getKey() => (string) $vessel->name])
            ->all();
    }

    /** @return array<string, string> */
    public static function operatorReasonOptions(): array
    {
        $options = [];

        foreach (BlockReason::cases() as $reason) {
            if ($reason->isOperatorOwned()) {
                $options[$reason->value] = $reason->label();
            }
        }

        return $options;
    }

    /** @return Builder<VesselBlock> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVesselBlocks::route('/'),
            'create' => Pages\CreateVesselBlock::route('/create'),
        ];
    }
}
