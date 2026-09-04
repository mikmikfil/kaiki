<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Availability\Actions\CreateManualDeparture;
use App\Domain\Availability\Actions\UpdateDeparture;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Enums\BookingMode;
use App\Enums\DepartureStatus;
use App\Filament\App\Resources\DepartureResource\Pages;
use App\Models\Departure;
use App\Models\Product;
use App\Support\Authorization\Capability;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Sailings, on `/app` (spec AVL-52, AVL-11, AVL-12, TEN-8, SEC-3).
 *
 * Thin (CNV-5): {@see CreateManualDeparture}
 * and {@see UpdateDeparture} hold every rule,
 * because the API and the importer will need them too.
 *
 * ## Crew see a window, not a catalogue
 *
 * The first resource where the row set itself depends on the role. TEN-8 makes
 * crew read-only, and a crew member standing on the quay needs tomorrow's list
 * rather than the season's — so the query is narrowed to
 * `kaiki.panel.crew_departure_window_days` either side of today for them, and
 * left alone for everyone else.
 *
 * That is a *scope* on top of the policy, not instead of it: the policy already
 * refuses crew every write. This narrows what they can read, which is a
 * different question and one a policy cannot answer per row without a query.
 *
 * ## Dates are the tenant's, not the server's
 *
 * The crew window is computed from `Carbon::now($tenantTimezone)`, because
 * "today" at 23:30 in Athens is tomorrow in UTC — and a crew member checking
 * the evening before a 07:00 sailing is exactly who would find that out.
 */
class DepartureResource extends Resource
{
    protected static ?string $model = Departure::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.catalogue');
    }

    public static function getNavigationLabel(): string
    {
        return __('availability.departure.nav');
    }

    public static function getModelLabel(): string
    {
        return __('availability.departure.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('availability.departure.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('availability.departure.sections.what'))
                ->schema([
                    Select::make('product_id')
                        ->label(__('availability.departure.form.product.label'))
                        ->helperText(__('availability.departure.form.product.help'))
                        // Only per-seat trips are offered: refusing on submit
                        // what the form offered is a worse conversation than
                        // not offering it.
                        ->options(static::productOptions(...))
                        ->required()
                        ->searchable()
                        ->preload()
                        // A departure's trip never changes — repointing it
                        // would re-price every booking on it retroactively.
                        ->disabledOn('edit'),

                    // `->timezone('UTC')` on both, and it is not a mistake.
                    //
                    // The panel registers a tenant display timezone once
                    // (CNV-2), so every date and time picker converts its state
                    // to UTC on the way out. That is right for a `timestamp`
                    // column and **wrong for these two**: `local_date` and
                    // `local_time` are wall-clock values by definition, not
                    // instants, and letting the panel convert them shifted an
                    // operator's 09:00 into 06:00 the previous day before the
                    // Action ever saw it. Setting the picker's timezone to the
                    // app's makes the conversion a no-op, so what the operator
                    // typed is what the resolver is given.
                    DatePicker::make('local_date')
                        ->label(__('availability.departure.form.local_date.label'))
                        ->timezone('UTC')
                        ->native(false)
                        ->required()
                        ->disabled(static fn (?Departure $record): bool => static::isSold($record)),

                    TimePicker::make('local_time')
                        ->label(__('availability.departure.form.local_time.label'))
                        ->helperText(__('availability.departure.form.local_time.help'))
                        ->timezone('UTC')
                        ->seconds(false)
                        ->native(false)
                        ->required()
                        ->disabled(static fn (?Departure $record): bool => static::isSold($record)),
                ])
                ->columns(2),

            Section::make(__('availability.departure.sections.seats'))
                ->schema([
                    TextInput::make('capacity')
                        ->label(__('availability.departure.form.capacity.label'))
                        ->helperText(__('availability.departure.form.capacity.help'))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(65535),
                ]),

            Section::make(__('availability.departure.sections.notes'))
                ->schema([
                    Textarea::make('notes')
                        ->label(__('availability.departure.form.notes.label'))
                        ->helperText(__('availability.departure.form.notes.help'))
                        ->rows(3)
                        ->maxLength(1000),

                    // AVL-11 is a warning, not a block. The operator is told
                    // what else uses the boat and decides — which is why this
                    // is a checkbox rather than a refusal.
                    Toggle::make('confirm_conflict')
                        ->label(__('availability.departure.form.confirm_conflict.label'))
                        ->helperText(__('availability.departure.form.confirm_conflict.help'))
                        ->dehydrated(true)
                        ->visibleOn('create'),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('local_date')
                    ->label(__('availability.departure.table.local_date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('local_time')
                    ->label(__('availability.departure.table.local_time')),

                TextColumn::make('product_id')
                    ->label(__('availability.departure.table.product'))
                    ->formatStateUsing(static fn (Departure $record): string => (string) $record->product?->title),

                TextColumn::make('vessel_id')
                    ->label(__('availability.departure.table.vessel'))
                    ->formatStateUsing(static fn (Departure $record): string => (string) $record->vessel?->name)
                    ->toggleable(),

                TextColumn::make('capacity')
                    ->label(__('availability.departure.table.capacity')),

                TextColumn::make('seats_sold')
                    ->label(__('availability.departure.table.seats_sold')),

                // Shown beside `seats_sold` rather than folded into it: §2.4
                // keeps the two disjoint, and "3 sold + 2 in checkout" is the
                // sentence the operator actually wants.
                TextColumn::make('seats_held')
                    ->label(__('availability.departure.table.seats_held')),

                TextColumn::make('status')
                    ->label(__('availability.departure.table.status'))
                    ->badge()
                    ->formatStateUsing(static fn (DepartureStatus $state): string => $state->label()),

                TextColumn::make('schedule_rule_id')
                    ->label(__('availability.departure.table.source'))
                    // §2.4 has no `is_manual` column — the absence of a rule is
                    // the fact, and this is where an operator reads it.
                    ->formatStateUsing(static fn (Departure $record): string => $record->schedule_rule_id === null
                        ? __('availability.departure.table.manual')
                        : __('availability.departure.table.generated'))
                    ->toggleable(),

                IconColumn::make('dst_ambiguous')
                    ->label(__('availability.departure.table.dst_ambiguous'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->defaultSort('starts_at_utc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('availability.departure.table.status'))
                    ->options(DepartureStatus::options()),
            ])
            ->actions([EditAction::make()]);
    }

    /**
     * The row set, narrowed to the crew window for crew (TEN-8).
     *
     * @return Builder<Departure>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (Auth::user()?->hasCapability(Capability::ManageCatalogue) === true) {
            return $query;
        }

        // "Today" in the tenant's timezone, not the server's: at 23:30 in
        // Athens the server's today is already tomorrow, and the crew member
        // checking the evening before a 07:00 sailing is exactly who finds out.
        $today = Carbon::now(LocalDateTimeResolver::timezone())->startOfDay();
        $days = max(0, (int) config('kaiki.panel.crew_departure_window_days', 1));

        $query->whereDate('local_date', '>=', $today->toDateString())
            ->whereDate('local_date', '<=', $today->copy()->addDays($days)->toDateString());

        return $query;
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

    /** Has anyone committed to this departure, in either sense? */
    public static function isSold(?Departure $record): bool
    {
        return $record !== null && ($record->seats_sold > 0 || $record->seats_held > 0);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDepartures::route('/'),
            'create' => Pages\CreateDeparture::route('/create'),
            'edit' => Pages\EditDeparture::route('/{record}/edit'),
        ];
    }
}
