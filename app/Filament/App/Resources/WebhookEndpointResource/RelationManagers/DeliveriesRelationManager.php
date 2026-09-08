<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WebhookEndpointResource\RelationManagers;

use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What we sent, what came back, and the button that tries again (OPS-20).
 *
 * *"…expose their delivery history in the panel"* and *"manually re-sendable"*.
 * Both halves matter and the second is the one operators actually use: the
 * common story is a receiver having a bad day, the operator fixing it, and
 * wanting yesterday's missed event without asking a guest to rebook.
 *
 * ## Resending is a reset, not a new event
 *
 * The same row, the same `event_id`, the same stored payload. That is what
 * makes it safe: `Kaiki-Delivery-Id` is stable, so a receiver that already
 * processed the event ignores the repeat, and one that never got it acts on it.
 * Creating a fresh delivery would give it a new id and defeat the consumer's
 * own deduplication — which is the mechanism §8.4 tells them to rely on.
 *
 * ## Only a failed delivery can be resent
 *
 * A `pending` one is already coming back on its own, `delivered` needs nothing,
 * and `abandoned` has nowhere to go. Offering the button on those would be
 * three different ways of doing nothing while looking like it did something.
 */
class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('webhooks.deliveries.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('webhooks.deliveries.when'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('event')
                    ->label(__('webhooks.deliveries.event'))
                    ->badge()
                    ->formatStateUsing(fn (WebhookEvent $state): string => $state->label()),

                TextColumn::make('status')
                    ->label(__('webhooks.deliveries.status'))
                    ->badge()
                    ->formatStateUsing(fn (DeliveryStatus $state): string => $state->label())
                    ->color(fn (DeliveryStatus $state): string => $state->color()),

                TextColumn::make('attempts')
                    ->label(__('webhooks.deliveries.attempts'))
                    ->formatStateUsing(fn (int $state): string => $state . '/' . WebhookDelivery::maxAttempts()),

                TextColumn::make('response_status')
                    ->label(__('webhooks.deliveries.response'))
                    ->placeholder(__('webhooks.deliveries.no_answer'))
                    // The body excerpt in a tooltip rather than a column: it is
                    // the thing you want when a row is red and noise the rest
                    // of the time.
                    ->tooltip(fn (WebhookDelivery $record): ?string => $record->response_body),

                TextColumn::make('next_attempt_at')
                    ->label(__('webhooks.deliveries.next'))
                    ->dateTime()
                    ->placeholder('—'),

                TextColumn::make('duration_ms')
                    ->label(__('webhooks.deliveries.duration'))
                    ->placeholder('—')
                    ->suffix(' ms')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('webhooks.deliveries.status'))
                    ->options(DeliveryStatus::options()),
            ])
            ->actions([
                Action::make('resend')
                    ->label(__('webhooks.deliveries.resend'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (WebhookDelivery $record): bool => $record->status === DeliveryStatus::Failed)
                    ->requiresConfirmation()
                    ->modalDescription(__('webhooks.deliveries.resend_description'))
                    ->action(function (WebhookDelivery $record): void {
                        // Reset rather than recreate. Same row, same event id,
                        // same payload — see the class docblock.
                        $record->forceFill([
                            'status' => DeliveryStatus::Pending,
                            'attempts' => 0,
                            'next_attempt_at' => Carbon::now(),
                            'response_status' => null,
                            'response_body' => null,
                        ])->save();

                        DeliverWebhook::dispatch($record->getKey());

                        Notification::make()
                            ->title(__('webhooks.deliveries.resent'))
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading(__('webhooks.deliveries.empty'));
    }
}
