<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Enums\AuditAction;
use App\Filament\App\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operator's own audit trail (ADR-0025 §4, spec SEC-16).
 *
 * ADR-0025's first complaint about the `Log::info` it replaced is what this
 * screen answers: a revocation is *"precisely when"* an operator needs to see
 * their own team's actions, and grepping a file on a Hetzner box is not seeing
 * them.
 *
 * ## Read-only, and structurally so
 *
 * There is **no form, no create page, no edit page and no delete action**, and
 * that is not restraint — {@see AuditLogPolicy} refuses every
 * write ability for every role including the owner, and {@see AuditLog} throws
 * if anything tries anyway. Three layers say the same thing because ADR-0025
 * asks for the property rather than the convention: *"an audit row that can be
 * edited is not an audit row."*
 *
 * ## Crew never see it
 *
 * `ViewAuditLog` is owner and manager (TEN-8, ADR-0025 §4). Filament reads the
 * policy for navigation as well as for the page, so a crew member gets neither
 * the menu item nor the URL.
 *
 * ## `user` is eager-loaded and nothing else is
 *
 * The actor is the one relation a row renders. The **subject is deliberately
 * not** a relation at all — `subject_type`/`subject_id` carry no foreign key
 * because the subject is usually deleted by the time anyone reads this, which
 * is why `subject_label` exists.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('audit.nav');
    }

    public static function getModelLabel(): string
    {
        return __('audit.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit.model_plural');
    }

    /**
     * The trail as an operator reads it: their tenant, newest first.
     *
     * `newestFirst()` is the model's own scope rather than an `orderByDesc`
     * here, so the panel and any future export cannot disagree about what
     * "newest" means when two actions land in the same second — a delete
     * usually fires several.
     *
     * @return Builder<AuditLog>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<AuditLog> $query */
        $query = parent::getEloquentQuery();

        return $query->with('user')->newestFirst();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('audit.when'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label(__('audit.actor'))
                    // Two different nulls, and the operator is told which.
                    // A system action never had an actor; an erased one had a
                    // person who has since exercised their GDPR rights, and the
                    // row deliberately no longer identifies them (ADR-0025 §3).
                    ->formatStateUsing(fn (?string $state): string => $state ?? __('audit.actor_system'))
                    ->default(null),

                TextColumn::make('action')
                    ->label(__('audit.action'))
                    ->badge(),

                TextColumn::make('subject_label')
                    ->label(__('audit.subject'))
                    ->description(fn (AuditLog $record): ?string => $record->subject_type)
                    ->placeholder(__('audit.subject_none')),

                TextColumn::make('reason')
                    ->label(__('audit.reason'))
                    ->wrap()
                    ->toggleable()
                    ->placeholder(__('audit.subject_none')),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('audit.filter_actor'))
                    // The tenant's own people. `relationship()` respects the
                    // global scope, so this cannot offer another operator's
                    // staff as a filter option.
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('action')
                    ->label(__('audit.filter_action'))
                    ->options(AuditAction::options())
                    ->multiple(),

                SelectFilter::make('subject_type')
                    ->label(__('audit.filter_subject'))
                    // Built from what is actually in this tenant's trail rather
                    // than from a hardcoded list of model names: the list grows
                    // every milestone, and an option nobody has rows for is an
                    // option that returns an empty table.
                    ->options(fn (): array => AuditLog::query()
                        ->whereNotNull('subject_type')
                        ->distinct()
                        ->pluck('subject_type', 'subject_type')
                        ->all()),
            ])
            // No bulk actions and no row actions: there is nothing to do to an
            // audit row.
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading(__('audit.empty_heading'))
            ->emptyStateDescription(__('audit.empty_description'));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('created_at')->label(__('audit.when'))->dateTime(),
            TextEntry::make('user.name')
                ->label(__('audit.actor'))
                ->placeholder(__('audit.actor_system')),
            TextEntry::make('action')->label(__('audit.action'))->badge(),
            TextEntry::make('subject_label')
                ->label(__('audit.subject'))
                ->placeholder(__('audit.subject_none')),
            TextEntry::make('reason')
                ->label(__('audit.reason'))
                ->placeholder(__('audit.subject_none')),
            TextEntry::make('ip_address')
                ->label(__('audit.ip'))
                ->placeholder(__('audit.subject_none')),
            // Rendered as a key/value list rather than raw JSON. `context` is
            // small and machine-readable by design (ADR-0025 §3) and carries no
            // personal data, so showing all of it is safe and showing none of
            // it would make the trail less useful than the log line it replaced.
            KeyValueEntry::make('context')->label(__('audit.context')),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        // List and view only. There is no create, edit or delete page because
        // there is no create, edit or delete.
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }

    /** @return list<class-string<User>> */
    public static function getRelations(): array
    {
        return [];
    }
}
