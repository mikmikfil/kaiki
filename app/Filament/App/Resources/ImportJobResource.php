<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Enums\ImportRowType;
use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Filament\App\Pages\Settings;
use App\Filament\App\Resources\ImportJobResource\Pages;
use App\Models\ImportJob;
use App\Policies\ImportJobPolicy;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Imports from WooCommerce / YITH Booking (spec SAA-13 … SAA-15).
 *
 * A list and a review page, and nothing that edits or deletes an import as a
 * record: the list answers "where is the import I started", the review page is
 * where its mapping is decided and where it is started or resumed. Owner only
 * ({@see ImportJobPolicy}).
 *
 * The list polls, for the reason the exports list does: an import is
 * asynchronous, and without polling an operator refreshes, sees the same
 * status and concludes it is stuck.
 */
class ImportJobResource extends Resource
{
    protected static ?string $model = ImportJob::class;

    protected static ?string $slug = 'imports';

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('imports.nav');
    }

    public static function getModelLabel(): string
    {
        return __('imports.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('imports.model_plural');
    }

    /** @return Builder<ImportJob> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<ImportJob> $query */
        $query = parent::getEloquentQuery();

        return $query->with('user')->orderByDesc('created_at')->orderByDesc('id');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('imports.table.created'))
                    ->dateTime(),

                TextColumn::make('source')
                    ->label(__('imports.table.source'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (ImportSource $state): string => $state->label()),

                TextColumn::make('status')
                    ->label(__('imports.table.status'))
                    ->badge()
                    ->color(static fn (ImportStatus $state): string => $state->color())
                    ->formatStateUsing(static fn (ImportStatus $state): string => $state->label())
                    ->description(static fn (ImportJob $record): ?string => $record->error_message),

                TextColumn::make('stats')
                    ->label(__('imports.table.summary'))
                    ->state(static fn (ImportJob $record): string => self::summary($record)),

                TextColumn::make('user.name')
                    ->label(__('imports.table.by'))
                    ->placeholder('—'),
            ])
            ->actions([
                Action::make('review')
                    ->label(__('imports.actions.review'))
                    ->icon('heroicon-o-clipboard-document-check')
                    ->url(static fn (ImportJob $record): string => self::getUrl('review', ['record' => $record])),
            ])
            ->bulkActions([])
            ->emptyStateHeading(__('imports.empty.heading'))
            ->emptyStateDescription(__('imports.empty.body'));
    }

    /** "3 trips, 7 bookings" — what the files held, whatever became of it. */
    public static function summary(ImportJob $record): string
    {
        $count = static fn (ImportRowType $type): int => array_sum((array) ($record->stats[$type->value] ?? []));

        return (string) __('imports.table.summary_line', [
            'products' => $count(ImportRowType::Product),
            'bookings' => $count(ImportRowType::Booking),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportJobs::route('/'),
            'review' => Pages\ReviewImport::route('/{record}'),
        ];
    }
}
