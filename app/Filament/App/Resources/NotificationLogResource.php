<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Notifications\Actions\RetryNotification;
use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationChannel;
use App\Enums\NotificationProvider;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTemplate;
use App\Filament\App\Resources\NotificationLogResource\Pages;
use App\Models\NotificationLog;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What we sent, and what failed to send (spec NTF-3, BKG-14, CNV-11).
 *
 * ## The failure feed BKG-14 asks for
 *
 * > *Failed listeners appear in the operator panel with a plain-Greek
 * > explanation and a retry button.*
 *
 * Both halves are here. The **explanation** is a translated sentence keyed on
 * the stored `error_message` — never the provider's own English, which is
 * written for a developer by a company the operator has never heard of (the
 * same rule PAY-12 sets for gateways). The **retry** rebuilds the message from
 * the booking as it stands now, because the reason it failed is often that
 * something needed correcting.
 *
 * ## The default view is the failures
 *
 * A log of every message ever sent is a list nobody opens. The feed opens
 * filtered to the rows that need a person, and the whole history is one filter
 * away — which is the difference between a screen an operator checks and one
 * they close.
 *
 * ## Nothing here can be deleted
 *
 * §2.7: pruned at twelve months by a scheduled job. A log an operator can tidy
 * cannot answer *"did the guest ever get the confirmation"*, which is the only
 * question it exists to answer.
 */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 26;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('notifications.nav');
    }

    public static function getModelLabel(): string
    {
        return __('notifications.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notifications.model.plural');
    }

    /**
     * How many need a person right now.
     *
     * Failures and bounces only. A badge counting every message ever sent is a
     * badge that always shows a large number and therefore says nothing.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = NotificationLog::query()->needingAttention()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('notifications.table.when'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('booking.reference')
                    ->label(__('notifications.table.booking'))
                    ->searchable(),

                TextColumn::make('template')
                    ->label(__('notifications.table.message'))
                    ->formatStateUsing(static fn (NotificationTemplate $state): string => $state->label()),

                TextColumn::make('channel')
                    ->label(__('notifications.table.channel'))
                    ->badge()
                    ->formatStateUsing(static fn (NotificationChannel $state): string => $state->label()),

                TextColumn::make('to')
                    ->label(__('notifications.table.to'))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('notifications.table.status'))
                    ->badge()
                    ->color(static fn (NotificationStatus $state): string => $state->needsAttention() ? 'danger' : 'gray')
                    ->formatStateUsing(static fn (NotificationStatus $state): string => $state->label()),

                TextColumn::make('provider')
                    ->label(__('notifications.table.provider'))
                    ->formatStateUsing(static fn (?NotificationProvider $state): string => $state?->label() ?? '—')
                    ->toggleable(),

                // CNV-11: the operator reads a sentence, not a provider's code.
                TextColumn::make('error_message')
                    ->label(__('notifications.table.why'))
                    ->formatStateUsing(static fn (?string $state): string => self::explain($state))
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('needs_attention')
                    ->label(__('notifications.filters.needs_attention'))
                    // The scope is on the model, and Filament hands the filter a
                    // plain `Builder` that PHPStan cannot see it through. The
                    // condition is written out rather than annotated away — it
                    // is two lines, and an `@var` here would be asserting
                    // something about Filament rather than about this table.
                    ->query(static fn (Builder $query): Builder => $query->whereIn('status', [
                        NotificationStatus::Failed->value,
                        NotificationStatus::Bounced->value,
                    ]))
                    // Opened filtered. See the class docblock: a log of every
                    // message ever sent is a list nobody opens.
                    ->default(),

                SelectFilter::make('status')
                    ->label(__('notifications.table.status'))
                    ->options(NotificationStatus::options()),

                SelectFilter::make('channel')
                    ->label(__('notifications.table.channel'))
                    ->options(NotificationChannel::options()),
            ])
            ->actions([static::retryAction()]);
        // No delete action anywhere — see the class docblock.
    }

    /** BKG-14's retry button. */
    public static function retryAction(): Action
    {
        return Action::make('retryNotification')
            ->label(__('notifications.actions.retry.label'))
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription(__('notifications.actions.retry.help'))
            ->visible(static fn (NotificationLog $record): bool => $record->status->needsAttention())
            ->action(static function (NotificationLog $record): void {
                $retried = app(RetryNotification::class)($record);

                Notification::make()
                    ->status($retried ? 'success' : 'warning')
                    ->title(__($retried ? 'notifications.actions.retry.done' : 'notifications.actions.retry.nothing'))
                    ->send();
            });
    }

    /**
     * A stored provider code, as a sentence an operator can act on.
     *
     * The codes are ours — written by the gateways and by
     * {@see SendNotification} — so the map is
     * closed and a missing entry falls back to a generic line rather than to
     * the raw string. PAY-12's rule about gateway errors, applied to the two
     * other providers that produce them.
     */
    public static function explain(?string $code): string
    {
        if ($code === null || trim($code) === '') {
            return '—';
        }

        $key = 'notifications.errors.' . $code;
        $explained = __($key);

        return $explained === $key ? __('notifications.errors.unknown') : $explained;
    }

    /** @return Builder<NotificationLog> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('booking');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationLogs::route('/'),
        ];
    }
}
