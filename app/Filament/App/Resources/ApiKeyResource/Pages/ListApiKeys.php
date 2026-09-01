<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ApiKeyResource\Pages;

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Filament\App\Resources\ApiKeyResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * The list, and the one place a plaintext key ever exists after creation.
 *
 * Creation is a **modal action rather than a create page**, and that is the
 * whole design. Filament's `CreateRecord` redirects when it is done, and
 * carrying the plaintext across a redirect means the session or a flash bag —
 * both of which CNV-13 forbids, and both of which outlive the moment the
 * operator is looking at the screen.
 *
 * Instead the key is minted and revealed inside a single Livewire round trip:
 * `create` mints it, hands it to `$revealedKey`, and swaps its own modal for
 * the reveal modal. Nothing is written to the session, nothing is redirected
 * through, and nothing is logged. Closing the modal clears the property, and a
 * page reload has nothing to show because the plaintext was never stored
 * anywhere it could be read back from.
 */
class ListApiKeys extends ListRecords
{
    protected static string $resource = ApiKeyResource::class;

    /**
     * The plaintext, for exactly as long as the reveal modal is open.
     *
     * `#[Locked]` so the browser cannot write to it — without that, a crafted
     * Livewire request could set it to anything and have the page echo it back,
     * which is a self-inflicted XSS reflector.
     */
    #[Locked]
    public ?string $revealedKey = null;

    #[Locked]
    public ?string $revealedKeyName = null;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('api_keys.actions.create.label'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('api_keys.actions.create.heading'))
                ->modalDescription(__('api_keys.actions.create.description'))
                ->modalSubmitActionLabel(__('api_keys.actions.create.submit'))
                ->form(ApiKeyResource::formSchema())
                ->authorize(fn (): bool => Auth::user()?->can('create', ApiKeyResource::getModel()) ?? false)
                ->action(function (array $data): void {
                    /** @var User|null $actor */
                    $actor = Auth::user();

                    $generated = app(GenerateApiKey::class)(
                        name: (string) $data['name'],
                        type: ApiKeyType::from((string) $data['type']),
                        scopes: array_values(array_map(
                            static fn (string $scope): ApiScope => ApiScope::from($scope),
                            (array) ($data['scopes'] ?? []),
                        )),
                        environment: ApiKeyEnvironment::from((string) $data['environment']),
                        allowedOrigins: array_values(array_map(
                            static fn (mixed $origin): string => (string) $origin,
                            (array) ($data['allowed_origins'] ?? []),
                        )),
                        createdBy: $actor,
                        expiresAt: $data['expires_at'] === null || $data['expires_at'] === ''
                            ? null
                            : now()->parse((string) $data['expires_at']),
                    );

                    $this->revealedKey = $generated->plainTextKey;
                    $this->revealedKeyName = $generated->apiKey->name;

                    // Same request, no redirect: the plaintext reaches the
                    // browser once and is never persisted server-side.
                    $this->replaceMountedAction('reveal');
                }),
        ];
    }

    /**
     * Resolved by name through `replaceMountedAction('reveal')`.
     *
     * Declared as a method rather than in `getHeaderActions()` so it is
     * mountable without also rendering a button nobody should press directly —
     * there is nothing to reveal until a key has just been created.
     */
    public function revealAction(): Action
    {
        return Action::make('reveal')
            ->modalHeading(__('api_keys.reveal.heading'))
            ->modalDescription(__('api_keys.reveal.warning'))
            ->modalContent(fn (): View => view('filament.api-key-reveal', [
                'key' => $this->revealedKey,
                'name' => $this->revealedKeyName,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('api_keys.reveal.done'))
            // Dismissing is a deliberate act: there is no second chance to read
            // this, so it must not vanish because a click landed off-target.
            ->closeModalByClickingAway(false);
    }

    /**
     * Forget the plaintext however the modal was dismissed.
     *
     * Not `->after()` on the action: that fires when an action *runs*, and the
     * reveal modal has nothing to run — it has no submit button. Escape, the X,
     * and the "I have saved it" button all land here instead, so this is the
     * one place that catches every way out.
     */
    public function unmountAction(bool $shouldCancelParentActions = true, bool $shouldCloseModal = true): void
    {
        $this->revealedKey = null;
        $this->revealedKeyName = null;

        parent::unmountAction($shouldCancelParentActions, $shouldCloseModal);
    }
}
