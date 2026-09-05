<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PortResource\Pages;
use App\Filament\Forms\TranslatableInput;
use App\Models\Port;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Ports and meeting points on `/app` (spec CAT-3, SEC-3).
 *
 * One resource for both roles, because there is one table: an operator's list
 * of places is their list of places, whether a boat sleeps there or guests meet
 * there. `docs/data-model.md` §2.3 is explicit that there is no separate
 * `meeting_points`.
 *
 * **Nothing here sorts or filters on a JSON path.** `name` and `instructions`
 * are translatable JSON columns, so the table goes through the companion
 * columns from #15 — `orderByTranslation()` and `whereTranslationMatches()`.
 * `NoJsonPathQueryTest` fails the build if that slips.
 */
class PortResource extends Resource
{
    protected static ?string $model = Port::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.catalogue');
    }

    public static function getNavigationLabel(): string
    {
        return __('catalog.port.nav');
    }

    public static function getModelLabel(): string
    {
        return __('catalog.port.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.port.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('catalog.port.sections.identity'))
                ->schema([
                    TranslatableInput::text(
                        'name',
                        __('catalog.port.form.name.label'),
                        __('catalog.port.form.name.help'),
                    ),

                    Toggle::make('is_active')
                        ->label(__('catalog.port.form.is_active.label'))
                        ->helperText(__('catalog.port.form.is_active.help'))
                        ->default(true),

                    TextInput::make('sort_order')
                        ->label(__('catalog.port.form.sort_order.label'))
                        ->helperText(__('catalog.port.form.sort_order.help'))
                        ->integer()
                        ->minValue(0)
                        // Matches unsignedSmallInteger; a larger number would be
                        // a silent overflow on MySQL and a wrong order on both.
                        ->maxValue(65535)
                        ->default(0),
                ])
                ->columns(2),

            Section::make(__('catalog.port.sections.location'))
                ->schema([
                    TextInput::make('address')
                        ->label(__('catalog.port.form.address.label'))
                        ->helperText(__('catalog.port.form.address.help'))
                        ->maxLength(255)
                        ->columnSpanFull(),

                    // The column is decimal(10,7) — the only decimals in the
                    // schema — so the form accepts the same precision. Rounding
                    // here would silently move the pin.
                    TextInput::make('lat')
                        ->label(__('catalog.port.form.lat.label'))
                        ->helperText(__('catalog.port.form.coordinates.help'))
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90)
                        ->step('0.0000001'),

                    TextInput::make('lng')
                        ->label(__('catalog.port.form.lng.label'))
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180)
                        ->step('0.0000001'),

                    TextInput::make('maps_url')
                        ->label(__('catalog.port.form.maps_url.label'))
                        ->helperText(__('catalog.port.form.maps_url.help'))
                        ->url()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    TranslatableInput::textarea(
                        'instructions',
                        __('catalog.port.form.instructions.label'),
                        __('catalog.port.form.instructions.help'),
                    ),
                ])
                ->columns(2),

            Section::make(__('catalog.port.sections.media'))
                ->schema([
                    // A path column, not a media row (ADR-0021 Option A). No
                    // resizing here: conversions arrive with StoreUploadedImage
                    // in #17, and this issue is explicitly scoped to storing a
                    // path.
                    FileUpload::make('photo_path')
                        ->label(__('catalog.port.form.photo_path.label'))
                        ->helperText(__('catalog.port.form.photo_path.help'))
                        ->image()
                        // The disk `GET /api/v1/products` builds URLs from (#36).
                        // Filament's default follows `FILESYSTEM_DISK`, which is
                        // `local` — a disk that cannot produce a URL at all, so the
                        // API returned nothing for every image the panel uploaded.
                        ->disk((string) config('kaiki.catalog.uploads.disk'))
                        ->directory('ports')
                        ->maxSize(5120),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('catalog.port.table.name'))
                    // Through the companion columns, never the JSON path.
                    ->sortable(query: self::sortByName(...))
                    ->searchable(query: self::searchByName(...)),

                TextColumn::make('address')
                    ->label(__('catalog.port.table.address'))
                    ->limit(48)
                    ->toggleable(),

                IconColumn::make('has_coordinates')
                    ->label(__('catalog.port.table.has_coordinates'))
                    ->boolean()
                    ->state(fn (Port $record): bool => $record->lat !== null && $record->lng !== null),

                TextColumn::make('vessels_count')
                    ->label(__('catalog.port.table.vessels_count'))
                    ->counts('vessels')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('catalog.port.table.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            // `sort_order` then the Greek-folded name: the operator's own order
            // first, alphabetical within it.
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('catalog.port.form.is_active.label')),
                TrashedFilter::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->searchPlaceholder(__('catalog.shared.search_placeholder'));
    }

    /**
     * Ordering, through the per-locale companion column.
     *
     * A named method rather than an inline closure so the `Builder` carries its
     * model: `orderByTranslation()` is a scope on {@see Port}, and a bare
     * `Builder` has no idea the scope exists — which PHPStan reports and which
     * would otherwise be silenced with an annotation that hides the same gap
     * everywhere else.
     *
     * @param  Builder<Port>  $query
     * @return Builder<Port>
     */
    public static function sortByName(Builder $query, string $direction): Builder
    {
        return $query->orderByTranslation('name', $direction);
    }

    /**
     * Searching, through the `search_index` haystack.
     *
     * @param  Builder<Port>  $query
     * @return Builder<Port>
     */
    public static function searchByName(Builder $query, string $search): Builder
    {
        return $query->whereTranslationMatches($search);
    }

    /**
     * Soft-deleted rows are reachable through the trashed filter, so the query
     * must not hide them at source.
     *
     * @return Builder<Port>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPorts::route('/'),
            'create' => Pages\CreatePort::route('/create'),
            'edit' => Pages\EditPort::route('/{record}/edit'),
        ];
    }
}
