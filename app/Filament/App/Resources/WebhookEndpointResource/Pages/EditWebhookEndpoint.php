<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WebhookEndpointResource\Pages;

use App\Filament\App\Resources\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * Editing an endpoint, rotating its secret, and switching it back on.
 *
 * ## Rotation is here rather than on the list
 *
 * It needs the endpoint's name in front of the person pressing it. A "rotate"
 * button in a row of a table is a button somebody presses against the wrong
 * row, and the consequence is every delivery to that receiver failing its
 * signature check until somebody notices.
 *
 * ## Re-enabling is a separate action from the `is_active` toggle
 *
 * The toggle is *"I do not want these right now"*. This is *"Kaiki switched
 * this off after twenty consecutive failures and I have fixed the cause"* —
 * which also has to clear `consecutive_failures`, or the very next failure
 * disables it again immediately. Two different intentions, and collapsing them
 * into one control is how an operator ends up unable to turn their integration
 * back on.
 */
class EditWebhookEndpoint extends EditRecord
{
    protected static string $resource = WebhookEndpointResource::class;

    #[Locked]
    public ?string $revealedSecret = null;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('rotate')
                ->label(__('webhooks.actions.rotate.label'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(__('webhooks.actions.rotate.heading'))
                // Named consequence rather than "are you sure": the operator
                // has to update their receiver, and saying so is the whole
                // value of the confirmation.
                ->modalDescription(__('webhooks.actions.rotate.description'))
                ->action(function (): void {
                    /** @var WebhookEndpoint $endpoint */
                    $endpoint = $this->getRecord();

                    $endpoint->forceFill(['signing_secret' => WebhookEndpoint::freshSecret()])->save();

                    $this->revealedSecret = $endpoint->signing_secret;

                    $this->replaceMountedAction('reveal');
                }),

            Action::make('reenable')
                ->label(__('webhooks.actions.reenable.label'))
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (WebhookEndpoint $record): bool => $record->disabled_at !== null)
                ->requiresConfirmation()
                ->modalDescription(__('webhooks.actions.reenable.description'))
                ->action(function (): void {
                    /** @var WebhookEndpoint $endpoint */
                    $endpoint = $this->getRecord();

                    // The counter has to go too. Leaving it at twenty means the
                    // next failure switches the endpoint straight back off.
                    $endpoint->forceFill([
                        'is_active' => true,
                        'disabled_at' => null,
                        'consecutive_failures' => 0,
                    ])->save();

                    Notification::make()
                        ->title(__('webhooks.actions.reenable.done'))
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }

    public function revealAction(): Action
    {
        return Action::make('reveal')
            ->modalHeading(__('webhooks.reveal.rotated_heading'))
            ->modalDescription(__('webhooks.reveal.warning'))
            ->modalContent(function (): View {
                /** @var WebhookEndpoint $endpoint */
                $endpoint = $this->getRecord();

                return view('filament.webhook-secret-reveal', [
                    'secret' => $this->revealedSecret,
                    'name' => $endpoint->name,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('webhooks.reveal.done'))
            ->closeModalByClickingAway(false);
    }

    public function unmountAction(bool $shouldCancelParentActions = true, bool $shouldCloseModal = true): void
    {
        $this->revealedSecret = null;

        parent::unmountAction($shouldCancelParentActions, $shouldCloseModal);
    }
}
