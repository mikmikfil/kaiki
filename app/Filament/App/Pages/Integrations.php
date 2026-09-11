<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Integrations\Actions\DeactivateIntegrationCredential;
use App\Domain\Integrations\Actions\SaveIntegrationCredential;
use App\Domain\Integrations\Actions\VerifyIntegrationCredential;
use App\Domain\Integrations\Data\IntegrationCredentialData;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Exceptions\IntegrationCredentialIncomplete;
use App\Models\IntegrationCredential;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The integrations screen on `/app` (spec PAY-4, PAY-11, TEN-8, EXT-2, I18N-1).
 *
 * ## A Page, like Branding, and for the same reason
 *
 * A resource would give an operator a list, a create button and a delete
 * button — and then have to take the delete away (deactivation is a flag, never
 * a row disappearing) and constrain the create to one row per provider per
 * environment. What an operator thinks they are doing is *setting up Viva*, so
 * the screen is a list of providers with their state, not a CRUD table over a
 * table they have never heard of.
 *
 * ## Owner only, checked twice
 *
 * `ManageGatewayCredentials` is TEN-8's owner-only capability. A page has no
 * model for Filament to find a policy on, so it falls back to allowing —
 * `canAccess()` closes that, and `mount()` closes it again for the case where a
 * link was already open when a role changed. Every action re-checks, because a
 * Livewire action is a POST an attacker can craft without ever loading the page.
 *
 * ## What this screen never shows
 *
 * A stored secret. Not once, not masked-but-selectable, not in a "reveal"
 * modal. The form's secret fields render **empty** with a placeholder saying a
 * value is saved, and an empty field on save means "leave it alone" rather than
 * "clear it" — which is the only behaviour that lets an operator change their
 * Viva source code without re-pasting their client secret. The last four
 * characters are the entire concession, from {@see IntegrationCredential::hint()}.
 */
class Integrations extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 95;

    protected static string $view = 'filament.app.pages.integrations';

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('integrations.nav');
    }

    public function getTitle(): string
    {
        return __('integrations.page.title');
    }

    public function getSubheading(): ?string
    {
        return __('integrations.page.subheading');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', IntegrationCredential::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * Everything this tenant has configured, newest state first.
     *
     * @return Collection<int, IntegrationCredential>
     */
    public function credentials(): Collection
    {
        return IntegrationCredential::query()
            ->orderBy('provider')
            ->orderBy('environment')
            ->get();
    }

    /**
     * Add or replace one provider's credentials.
     *
     * One action for both, because they are the same operation against a unique
     * (`tenant_id`, `provider`, `environment`) key — see
     * {@see SaveIntegrationCredential}. Passing `credential` pre-fills the
     * non-secret half so an operator editing a sender name is not made to
     * re-paste an API key to do it.
     */
    public function saveCredentialsAction(): Action
    {
        return Action::make('saveCredentials')
            ->label(__('integrations.actions.save'))
            ->modalHeading(__('integrations.page.title'))
            ->form($this->credentialForm())
            ->fillForm(fn (array $arguments): array => $this->prefill($arguments))
            ->action(function (array $data): void {
                abort_unless(Gate::allows('create', IntegrationCredential::class), 403);

                $provider = IntegrationProvider::from((string) $data['provider']);
                $environment = CredentialEnvironment::from((string) $data['environment']);

                try {
                    app(SaveIntegrationCredential::class)(new IntegrationCredentialData(
                        provider: $provider,
                        environment: $environment,
                        credentials: $this->mergeSecrets($provider, $environment, (array) ($data['credentials'] ?? [])),
                        publicConfig: (array) ($data['public_config'] ?? []),
                        webhookSecret: $this->mergeWebhookSecret($provider, $environment, $data['webhook_secret'] ?? null),
                        isDefault: (bool) ($data['is_default'] ?? false),
                        isActive: (bool) ($data['is_active'] ?? true),
                    ));
                } catch (IntegrationCredentialIncomplete $refused) {
                    // A refusal is a sentence, not a 500. It names the missing
                    // fields and never their values — see the exception.
                    Notification::make()->title($refused->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('integrations.actions.saved'))->success()->send();
            });
    }

    /** Run a real call against the stored keys and show the operator the answer. */
    public function verifyAction(): Action
    {
        return Action::make('verify')
            ->label(__('integrations.verify.action'))
            ->action(function (array $arguments): void {
                $credential = $this->requireCredential($arguments);

                abort_unless(Gate::allows('update', $credential), 403);

                $result = app(VerifyIntegrationCredential::class)($credential);

                // Both branches carry the sentence. A bare "failed" would be
                // the one thing an operator cannot act on, and CNV-11 asks for
                // the reason in their own language (`$result->message()` is
                // already translated).
                Notification::make()
                    ->title($result->succeeded
                        ? __('integrations.verify.succeeded', ['provider' => $credential->provider->label()])
                        : __('integrations.verify.failed'))
                    ->body($result->succeeded ? null : $result->message())
                    ->status($result->succeeded ? 'success' : 'danger')
                    ->send();
            });
    }

    /** Switch an integration off, keeping the keys (see the Action for why). */
    public function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label(__('integrations.actions.deactivate'))
            ->color('gray')
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $credential = $this->requireCredential($arguments);

                abort_unless(Gate::allows('update', $credential), 403);

                app(DeactivateIntegrationCredential::class)($credential);

                Notification::make()->title(__('integrations.actions.deactivated'))->success()->send();
            });
    }

    /** @return array<int, Component> */
    protected function credentialForm(): array
    {
        return [
            Select::make('provider')
                ->label(__('integrations.form.provider.label'))
                ->options(IntegrationProvider::options())
                ->required()
                ->native(false)
                // The fields below depend on it, so the form has to re-render
                // when it changes. Without `live()` an operator switching from
                // Viva to Stripe sees Viva's fields and fills them in.
                ->live(),

            Select::make('environment')
                ->label(__('integrations.form.environment.label'))
                ->helperText(__('integrations.form.environment.help'))
                ->options(CredentialEnvironment::options())
                ->default(CredentialEnvironment::Test->value)
                ->required()
                ->native(false),

            Group::make()
                ->schema(fn (Get $get): array => $this->providerFields($get('provider')))
                ->columns(1),

            Toggle::make('is_default')
                ->label(__('integrations.form.is_default.label'))
                ->helperText(__('integrations.form.is_default.help'))
                ->visible(fn (Get $get): bool => $this->providerFrom($get('provider'))?->isPaymentGateway() === true),

            Toggle::make('is_active')
                ->label(__('integrations.form.is_active.label'))
                ->default(true),
        ];
    }

    /**
     * The fields one provider actually needs.
     *
     * Built from the enum rather than from a list here, so a provider gaining a
     * field is one edit in {@see IntegrationProvider} and not a form that
     * silently keeps asking for the old set.
     *
     * @return array<int, Component>
     */
    protected function providerFields(mixed $providerValue): array
    {
        $provider = $this->providerFrom($providerValue);

        if ($provider === null) {
            return [];
        }

        $fields = [];

        foreach ($provider->credentialFields() as $field) {
            $fields[] = TextInput::make("credentials.{$field}")
                ->label(__("integrations.fields.{$field}"))
                ->password()
                // Never `->revealable()`. The value is not in the form state to
                // begin with, so revealing it would show an empty box — and if
                // it ever were, this is the switch that would put a live gateway
                // secret on screen in an office with a window.
                ->autocomplete(false)
                ->placeholder(__('integrations.form.secret_placeholder'))
                ->maxLength(500);
        }

        if ($provider->issuesWebhookSecret()) {
            $fields[] = TextInput::make('webhook_secret')
                ->label(__('integrations.fields.webhook_secret'))
                ->password()
                ->autocomplete(false)
                ->placeholder(__('integrations.form.secret_placeholder'))
                ->maxLength(500);
        }

        foreach ($provider->publicFields() as $field) {
            // Plain text, on purpose: this is the half an operator has to be
            // able to read back to check it against their vendor dashboard.
            $fields[] = TextInput::make("public_config.{$field}")
                ->label(__("integrations.fields.{$field}"))
                ->maxLength(190);
        }

        return $fields;
    }

    /**
     * An empty secret field means "keep what is stored".
     *
     * The behaviour the whole form depends on. Secrets are never rendered, so
     * every field arrives empty — treating that as a clear would wipe an
     * operator's Stripe key the first time they corrected a typo in their
     * sender name.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, string>
     */
    protected function mergeSecrets(IntegrationProvider $provider, CredentialEnvironment $environment, array $submitted): array
    {
        $existing = $this->existing($provider, $environment);
        $merged = $existing === null ? [] : $existing->credentials;

        foreach ($provider->credentialFields() as $field) {
            $value = $submitted[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $merged[$field] = trim($value);
            }
        }

        return $merged;
    }

    protected function mergeWebhookSecret(IntegrationProvider $provider, CredentialEnvironment $environment, mixed $submitted): ?string
    {
        if (is_string($submitted) && trim($submitted) !== '') {
            return trim($submitted);
        }

        return $this->existing($provider, $environment)?->webhook_secret;
    }

    /**
     * Pre-fill the readable half only.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function prefill(array $arguments): array
    {
        $credential = $this->findCredential($arguments);

        if ($credential === null) {
            return [];
        }

        return [
            'provider' => $credential->provider->value,
            'environment' => $credential->environment->value,
            // Deliberately no `credentials` key. See the class docblock.
            'public_config' => $credential->public_config,
            'is_default' => $credential->is_default,
            'is_active' => $credential->is_active,
        ];
    }

    protected function existing(IntegrationProvider $provider, CredentialEnvironment $environment): ?IntegrationCredential
    {
        return IntegrationCredential::query()
            ->where('provider', $provider->value)
            ->where('environment', $environment->value)
            ->first();
    }

    /** @param  array<string, mixed>  $arguments */
    protected function findCredential(array $arguments): ?IntegrationCredential
    {
        $id = $arguments['credential'] ?? null;

        // Through the tenant-scoped query, so an id belonging to another
        // operator is not found rather than being found and then refused.
        return is_numeric($id) ? IntegrationCredential::query()->find((int) $id) : null;
    }

    /** @param  array<string, mixed>  $arguments */
    protected function requireCredential(array $arguments): IntegrationCredential
    {
        $credential = $this->findCredential($arguments);

        abort_if($credential === null, 404);

        return $credential;
    }

    protected function providerFrom(mixed $value): ?IntegrationProvider
    {
        return is_string($value) ? IntegrationProvider::tryFrom($value) : null;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [$this->saveCredentialsAction()];
    }

    /**
     * The status line for one row, already translated (CNV-11).
     *
     * Here rather than in the Blade because it is a decision with an order —
     * switched off beats not-working beats not-checked — and a chain of
     * `@if`s in a template is where that order goes wrong silently.
     *
     * @return array{label: string, color: string}
     */
    public function statusOf(IntegrationCredential $credential): array
    {
        if (! $credential->is_active) {
            return ['label' => __('integrations.status.inactive'), 'color' => 'gray'];
        }

        if (! $credential->isComplete()) {
            return ['label' => __('integrations.status.incomplete'), 'color' => 'warning'];
        }

        if ($credential->last_error !== null) {
            return ['label' => __('integrations.status.failed'), 'color' => 'danger'];
        }

        if ($credential->verified_at === null) {
            return ['label' => __('integrations.status.unverified'), 'color' => 'warning'];
        }

        return [
            'label' => __('integrations.status.verified', [
                'when' => $credential->verified_at->diffForHumans(),
            ]),
            'color' => 'success',
        ];
    }

    /** @return array<int, Section> */
    protected function getFormSchema(): array
    {
        return [];
    }
}
