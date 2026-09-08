<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\WebhookEndpointResource\Pages;

use App\Filament\App\Resources\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * The list, and the one moment a signing secret is readable (OPS-20).
 *
 * ## Created through a modal action rather than a `CreateRecord` page
 *
 * The same shape `ListApiKeys` uses, for the same reason: the secret has to
 * reach the browser in the **response to the create**, and a redirect to an
 * index page loses it. Creating in place lets the modal be swapped for the
 * reveal without a round trip.
 *
 * ## `#[Locked]`, and it is not decoration
 *
 * Without it a crafted Livewire request could set `$revealedSecret` to anything
 * and have the page echo it back — a self-inflicted XSS reflector on a screen
 * whose whole job is displaying a credential.
 */
class ListWebhookEndpoints extends ListRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    /** The plaintext, for exactly as long as the reveal modal is open. */
    #[Locked]
    public ?string $revealedSecret = null;

    #[Locked]
    public ?string $revealedName = null;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('webhooks.actions.create.label'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('webhooks.actions.create.heading'))
                ->modalDescription(__('webhooks.actions.create.description'))
                ->modalSubmitActionLabel(__('webhooks.actions.create.submit'))
                ->form(WebhookEndpointResource::formSchema())
                ->authorize(fn (): bool => Auth::user()?->can('create', WebhookEndpoint::class) ?? false)
                ->action(function (array $data): void {
                    $endpoint = WebhookEndpoint::query()->create([
                        'name' => (string) $data['name'],
                        'url' => (string) $data['url'],
                        'events' => (array) ($data['events'] ?? []),
                        'is_active' => (bool) ($data['is_active'] ?? true),
                    ]);

                    $this->revealedSecret = $endpoint->signing_secret;
                    $this->revealedName = $endpoint->name;

                    // Same request, no redirect. See the class docblock.
                    $this->replaceMountedAction('reveal');
                }),
        ];
    }

    /**
     * Resolved by name through `replaceMountedAction('reveal')`.
     *
     * A method rather than a header action, so it is mountable without also
     * rendering a button nobody should press directly — there is nothing to
     * reveal until a secret has just been minted.
     */
    public function revealAction(): Action
    {
        return Action::make('reveal')
            ->modalHeading(__('webhooks.reveal.heading'))
            ->modalDescription(__('webhooks.reveal.warning'))
            ->modalContent(fn (): View => view('filament.webhook-secret-reveal', [
                'secret' => $this->revealedSecret,
                'name' => $this->revealedName,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('webhooks.reveal.done'))
            // There is no second chance to read this, so it must not vanish
            // because a click landed off-target.
            ->closeModalByClickingAway(false);
    }

    /**
     * Forget the plaintext however the modal was dismissed.
     *
     * Not `->after()`: that fires when an action *runs*, and this modal has
     * nothing to run. Escape, the X and the "I have saved it" button all land
     * here, so it is the one place that catches every way out.
     */
    public function unmountAction(bool $shouldCancelParentActions = true, bool $shouldCloseModal = true): void
    {
        $this->revealedSecret = null;
        $this->revealedName = null;

        parent::unmountAction($shouldCancelParentActions, $shouldCloseModal);
    }
}
