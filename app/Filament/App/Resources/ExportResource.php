<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Filament\App\Resources\ExportResource\Pages;
use App\Jobs\RunExportJob;
use App\Models\ExportJob;
use App\Policies\ExportJobPolicy;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * The exports an operator has asked for (spec OPS-17, OPS-18).
 *
 * ## A list, not a form
 *
 * The screen's job is not "make a CSV" — that is one modal. Its job is to
 * answer *"where is the file I asked for"*, which is a question about a queue,
 * and a queue is a list. So the resource is read-only with a single header
 * action, and every row says what it covers, what state it is in and how long
 * its link has left.
 *
 * ## It polls, and that is a real requirement rather than a flourish
 *
 * An export is asynchronous. Without polling, an operator presses the button,
 * sees a `queued` row, and has to guess when to refresh — so they refresh
 * immediately, see the same row, and conclude it is stuck. Ten seconds is
 * frequent enough that a small export appears to complete while they watch, and
 * rare enough to be free.
 *
 * ## Nothing on this screen deletes anything
 *
 * {@see ExportJobPolicy} refuses every write ability. The row is the log OPS-18
 * asks for, and the file behind it is removed on expiry by the sweeper rather
 * than by a person — so there is no button whose meaning would be ambiguous
 * between "remove the file" and "remove the record that I took it".
 */
class ExportResource extends Resource
{
    protected static ?string $model = ExportJob::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('exports.nav');
    }

    public static function getModelLabel(): string
    {
        return __('exports.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exports.model_plural');
    }

    /**
     * This tenant's exports, newest first.
     *
     * `newestFirst()` is the model's scope rather than an `orderByDesc` here,
     * so two exports asked for in the same second cannot be ordered one way on
     * this screen and another way anywhere else.
     *
     * @return Builder<ExportJob>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<ExportJob> $query */
        $query = parent::getEloquentQuery();

        return $query->with('user')->newestFirst();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('exports.table.requested'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('exports.table.type'))
                    ->badge()
                    ->formatStateUsing(static fn (ExportType $state): string => $state->label()),

                TextColumn::make('from_date')
                    ->label(__('exports.table.window'))
                    // The window and the basis together, because either alone
                    // is unreadable: "September" answers nothing without
                    // "measured on the date of the trip".
                    ->formatStateUsing(static fn (?string $state, ExportJob $record): string => self::window($record))
                    ->description(static fn (ExportJob $record): string => $record->date_basis->label())
                    ->placeholder(__('exports.table.window_all')),

                TextColumn::make('status')
                    ->label(__('exports.table.status'))
                    ->badge()
                    ->color(static fn (ExportStatus $state): string => $state->color())
                    ->formatStateUsing(static fn (ExportStatus $state): string => $state->label())
                    // A failure explains itself in the operator's own language
                    // rather than making them ask (NFR-8).
                    ->description(static fn (ExportJob $record): ?string => $record->error),

                TextColumn::make('row_count')
                    ->label(__('exports.table.rows'))
                    ->numeric()
                    // A zero is an answer — an empty window rather than a
                    // broken export — so it is shown rather than blanked.
                    ->visible(static fn (): bool => true),

                TextColumn::make('byte_size')
                    ->label(__('exports.table.size'))
                    ->formatStateUsing(static fn (int $state): string => $state > 0
                        ? Number::fileSize($state)
                        : '—')
                    ->toggleable(),

                TextColumn::make('expires_at')
                    ->label(__('exports.table.expires'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('user.name')
                    ->label(__('exports.table.by'))
                    // The person has left; the row has not. The same two-nulls
                    // distinction the audit trail makes.
                    ->formatStateUsing(static fn (?string $state): string => $state ?? __('exports.table.by_system'))
                    ->toggleable(),

                TextColumn::make('download_count')
                    ->label(__('exports.table.downloads'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('exports.table.type'))
                    ->options(ExportType::options()),

                SelectFilter::make('status')
                    ->label(__('exports.table.status'))
                    ->options(ExportStatus::options()),
            ])
            ->actions([
                Action::make('download')
                    ->label(__('exports.action.download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    // Both halves of `isDownloadable()`: the job finished, and
                    // the day has not passed. A button that 410s is worse than
                    // no button.
                    ->visible(static fn (ExportJob $record): bool => $record->isDownloadable())
                    ->url(static fn (ExportJob $record): string => route(
                        'filament.app.exports.download',
                        ['uuid' => $record->uuid],
                    )),
            ])
            // No bulk actions. Nothing here is deletable, and a checkbox column
            // with nothing behind it invites the question.
            ->bulkActions([])
            ->emptyStateHeading(__('exports.empty.heading'))
            ->emptyStateDescription(__('exports.empty.body'));
    }

    /**
     * The date window as a person reads it.
     *
     * An open end is *"from 1 June"* rather than *"1 June – "*, because a
     * trailing dash reads as a rendering fault and sends somebody to check
     * whether the export was truncated.
     */
    private static function window(ExportJob $record): string
    {
        $from = $record->from_date?->toDateString();
        $to = $record->to_date?->toDateString();

        return match (true) {
            $from !== null && $to !== null => $from . ' – ' . $to,
            $from !== null => '≥ ' . $from,
            $to !== null => '≤ ' . $to,
            default => (string) __('exports.table.window_all'),
        };
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExports::route('/'),
        ];
    }

    /** How long a finished link lives, for the notice on the form. */
    public static function ttlHours(): int
    {
        return RunExportJob::ttlHours();
    }
}
