<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Notifications\Actions\RetryNotification;
use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\NotificationChannel;
use App\Enums\NotificationProvider;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTemplate;
use App\Filament\App\Pages\Settings;
use App\Filament\App\Resources\NotificationLogResource\Pages;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Support\Tenancy;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
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

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
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
        // The login page builds navigation too, and has no tenant. Same trap as
        // {@see EnquiryResource::getNavigationBadge()}, and the same
        // consequence: a 500 on the one page nobody can be signed in to fix.
        if (! Tenancy::check()) {
            return null;
        }

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
                // Four columns, not eight (Mike, 2026-09-23: «χάλια εμφάνιση,
                // προτάσεις κόβονται»). Eight pushed «Τι πήγε στραβά» and both
                // buttons off the right edge of a laptop, and the explanation,
                // wrapping inside a column a few words wide, made every row
                // two hundred pixels tall. The recipient now sits under the
                // message and the explanation under the status — the shape
                // «Παραστατικά» already uses for a failed invoice.
                TextColumn::make('created_at')
                    ->label(__('notifications.table.when'))
                    ->dateTime('d/m/Y H:i')
                    ->description(static fn (NotificationLog $record): ?string => $record->booking?->reference)
                    ->sortable(),

                // Under the date rather than a column of its own, so the two
                // buttons still fit on a tablet; searchable all the same.
                TextColumn::make('booking.reference')
                    ->label(__('notifications.table.booking'))
                    ->searchable()
                    ->hidden(),

                TextColumn::make('template')
                    ->label(__('notifications.table.message'))
                    ->formatStateUsing(static fn (NotificationTemplate $state): string => $state->label())
                    ->description(static fn (NotificationLog $record): string => (string) $record->to),

                // Searchable on its own, since it is no longer a column: the
                // question «did Maria get hers?» is asked by address.
                TextColumn::make('to')
                    ->label(__('notifications.table.to'))
                    ->searchable()
                    ->hidden(),

                TextColumn::make('status')
                    ->label(__('notifications.table.status'))
                    ->badge()
                    ->color(static fn (NotificationStatus $state): string => $state->needsAttention() ? 'danger' : 'gray')
                    ->formatStateUsing(static fn (NotificationStatus $state): string => $state->label())
                    // CNV-11: the operator reads a sentence, not a provider's
                    // code — and only where something went wrong.
                    ->description(static fn (NotificationLog $record): ?string => $record->status->needsAttention()
                        ? self::explain($record->error_message)
                        : null)
                    ->wrap()
                    // The one column with a sentence in it takes the room the
                    // others do not need, instead of wrapping to seven lines.
                    ->grow()
                    ->extraAttributes(['style' => 'min-width: 12rem']),

                TextColumn::make('channel')
                    ->label(__('notifications.table.channel'))
                    ->badge()
                    ->formatStateUsing(static fn (NotificationChannel $state): string => $state->label())
                    ->visibleFrom('2xl'),

                TextColumn::make('provider')
                    ->label(__('notifications.table.provider'))
                    ->formatStateUsing(static fn (?NotificationProvider $state): string => $state?->label() ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),
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
            // As icons here, with the label as a tooltip: two worded buttons
            // were wider than the message they act on. The same actions keep
            // their words wherever else they are used.
            ->actions([
                static::previewAction()->iconButton()->tooltip(__('notifications.actions.preview.label')),
                static::retryAction()->iconButton()->tooltip(__('notifications.actions.retry.label')),
            ]);
        // No delete action anywhere — see the class docblock.
    }

    /**
     * What the guest's email looks like (product owner, 2026-09-17).
     *
     * **Rebuilt, not replayed.** The log keeps who, when and which template,
     * never the body: a copy of every email would be a copy of every guest's
     * personal data for a year. So the preview renders the same mailable the
     * retry would send, from the booking as it stands now, and says so.
     *
     * Shown in a sandboxed frame, so the email's own styles cannot reach the
     * panel and nothing in it can run or navigate.
     */
    public static function previewAction(): Action
    {
        return Action::make('previewEmail')
            ->label(__('notifications.actions.preview.label'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(static fn (NotificationLog $record): bool => $record->channel === NotificationChannel::Mail
                && $record->booking instanceof Booking)
            ->modalHeading(static fn (NotificationLog $record): string => $record->template->label())
            ->modalDescription(__('notifications.actions.preview.help'))
            ->modalContent(static function (NotificationLog $record): View {
                /** @var Booking $booking */
                $booking = $record->booking;
                $mail = new GuestMail($booking, $record->template);

                return view('filament.app.email-preview', [
                    'subject' => (string) $mail->envelope()->subject,
                    'to' => $record->to,
                    'html' => $mail->render(),
                ]);
            })
            ->modalWidth(MaxWidth::FourExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('notifications.actions.preview.close'));
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
