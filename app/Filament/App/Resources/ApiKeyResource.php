<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Tenancy\Actions\RevokeApiKey;
use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Filament\App\Resources\ApiKeyResource\Pages;
use App\Models\ApiKey;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The operator's API keys (spec TEN-3, SEC-5, ADR-0013 Option A).
 *
 * Thin by design (CNV-5): every mutation calls the Actions from #6. Nothing
 * here knows how a key is minted, hashed or revoked — this class decides only
 * what an operator sees and what they may press.
 *
 * The list shows `prefix` and `last_four` and nothing else of the key. The
 * plaintext exists exactly once, in the response to the create action, and is
 * rendered by {@see Pages\ListApiKeys}.
 */
class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('api_keys.nav');
    }

    public static function getModelLabel(): string
    {
        return __('api_keys.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('api_keys.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /**
     * The create form, also mounted by the list page's modal action.
     *
     * @return array<int, Component>
     */
    public static function formSchema(): array
    {
        return [
            TextInput::make('name')
                ->label(__('api_keys.form.name.label'))
                ->helperText(__('api_keys.form.name.help'))
                ->required()
                // Matches the column; a longer name would be a silent truncation.
                ->maxLength(80),

            Select::make('type')
                ->label(__('api_keys.form.type.label'))
                ->helperText(__('api_keys.form.type.help'))
                ->options(fn (): array => collect(ApiKeyType::cases())
                    ->mapWithKeys(fn (ApiKeyType $type): array => [
                        $type->value => $type->label(),
                    ])
                    ->all())
                ->default(ApiKeyType::Publishable->value)
                ->required()
                // The scope list and the origins field both depend on this.
                ->live()
                ->afterStateUpdated(fn (callable $set) => $set('scopes', [])),

            Select::make('environment')
                ->label(__('api_keys.form.environment.label'))
                ->options(fn (): array => collect(ApiKeyEnvironment::cases())
                    ->mapWithKeys(fn (ApiKeyEnvironment $env): array => [
                        $env->value => $env->label(),
                    ])
                    ->all())
                ->default(ApiKeyEnvironment::Live->value)
                ->required(),

            CheckboxList::make('scopes')
                ->label(__('api_keys.form.scopes.label'))
                ->helperText(__('api_keys.form.scopes.help'))
                // Narrowed by the selected type, from the same method the
                // domain Action enforces with. Type is the ceiling; scopes only
                // narrow it (SEC-5).
                ->options(fn (Get $get): array => collect(self::typeFrom($get('type'))->allowedScopes())
                    ->mapWithKeys(fn (ApiScope $scope): array => [$scope->value => $scope->label()])
                    ->all())
                ->required()
                ->rules([
                    // The options list is a UI affordance; a tampered payload
                    // must fail as a form error rather than as the Action's
                    // InvalidArgumentException becoming a 500.
                    fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $type = self::typeFrom($get('type'));

                        foreach ((array) $value as $scope) {
                            $candidate = ApiScope::tryFrom((string) $scope);

                            if ($candidate === null || ! $type->permits($candidate)) {
                                $fail(__('api_keys.form.scopes.forbidden', [
                                    'scope' => (string) $scope,
                                    'type' => $type->label(),
                                ]));
                            }
                        }
                    },
                ]),

            TagsInput::make('allowed_origins')
                ->label(__('api_keys.form.allowed_origins.label'))
                // A warning, never a blocker: an empty list is a legitimate
                // choice for a server-to-server integration.
                ->helperText(fn (Get $get): string => $get('allowed_origins') === [] || $get('allowed_origins') === null
                    ? __('api_keys.form.allowed_origins.empty_warning')
                    : __('api_keys.form.allowed_origins.help'))
                ->live(onBlur: true)
                ->placeholder(__('api_keys.form.allowed_origins.placeholder'))
                ->visible(fn (Get $get): bool => self::typeFrom($get('type')) === ApiKeyType::Publishable),

            DateTimePicker::make('expires_at')
                ->label(__('api_keys.form.expires_at.label'))
                ->helperText(__('api_keys.form.expires_at.help'))
                ->seconds(false)
                ->minDate(now()),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('api_keys.table.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('api_keys.table.type'))
                    ->badge()
                    ->formatStateUsing(fn (ApiKeyType $state): string => $state->label())
                    ->color(fn (ApiKeyType $state): string => $state->isPublic() ? 'gray' : 'warning'),

                TextColumn::make('environment')
                    ->label(__('api_keys.table.environment'))
                    ->badge()
                    ->formatStateUsing(fn (ApiKeyEnvironment $state): string => $state->label())
                    ->color(fn (ApiKeyEnvironment $state): string => $state === ApiKeyEnvironment::Live ? 'success' : 'gray'),

                // The only part of the key that is ever shown again (TEN-3).
                TextColumn::make('prefix')
                    ->label(__('api_keys.table.prefix'))
                    ->copyable()
                    ->copyMessage(__('api_keys.table.prefix_copied'))
                    ->formatStateUsing(fn (string $state): string => "{$state}…"),

                TextColumn::make('last_four')
                    ->label(__('api_keys.table.last_four'))
                    ->formatStateUsing(fn (string $state): string => "…{$state}")
                    ->toggleable(),

                TextColumn::make('last_used_at')
                    ->label(__('api_keys.table.last_used_at'))
                    ->dateTime()
                    ->placeholder(__('api_keys.table.never_used'))
                    ->sortable()
                    // ADR-0013: the panel warns when a key has gone unused.
                    // A key nobody uses is a key nobody notices leaking.
                    ->color(fn (?ApiKey $record): ?string => $record !== null && self::isStale($record) ? 'warning' : null)
                    ->description(fn (?ApiKey $record): ?string => $record !== null && self::isStale($record)
                        ? __('api_keys.table.stale_warning', ['days' => static::staleAfterDays()])
                        : null),

                TextColumn::make('status')
                    ->label(__('api_keys.table.status'))
                    ->badge()
                    ->state(fn (ApiKey $record): string => self::statusOf($record))
                    ->formatStateUsing(fn (string $state): string => __("api_keys.status.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'revoked' => 'danger',
                        'expired' => 'gray',
                        default => 'success',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('revoke')
                    ->label(__('api_keys.actions.revoke.label'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    // SEC-16: destructive, so it is confirmed and logged.
                    ->requiresConfirmation()
                    ->modalHeading(fn (ApiKey $record): string => __('api_keys.actions.revoke.heading', ['name' => $record->name]))
                    ->modalDescription(__('api_keys.actions.revoke.description'))
                    ->modalSubmitActionLabel(__('api_keys.actions.revoke.confirm'))
                    ->visible(fn (ApiKey $record): bool => ! $record->isRevoked())
                    ->authorize(fn (ApiKey $record): bool => Auth::user()?->can('update', $record) ?? false)
                    ->action(function (ApiKey $record): void {
                        app(RevokeApiKey::class)($record);

                        // The audit trail SEC-16 asks for. There is no audit
                        // table in the data model and inventing one is a DECIDE
                        // (CLAUDE.md), so this is a structured line carrying the
                        // actor and the key — never the hash, never a plaintext.
                        Log::info('api_key.revoked', [
                            'api_key_id' => $record->getKey(),
                            'api_key_prefix' => $record->prefix,
                            'actor_user_id' => Auth::id(),
                        ]);

                        Notification::make()
                            ->title(__('api_keys.actions.revoke.done', ['name' => $record->name]))
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading(__('api_keys.empty.heading'))
            ->emptyStateDescription(__('api_keys.empty.description'));
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        // No create page on purpose. Creation is a modal action on the list
        // page, because the plaintext must not survive a redirect (CNV-13).
        return [
            'index' => Pages\ListApiKeys::route('/'),
        ];
    }

    public static function staleAfterDays(): int
    {
        return (int) config('kaiki.api_keys.unused_warning_days');
    }

    private static function isStale(ApiKey $key): bool
    {
        if ($key->isRevoked() || $key->last_used_at === null) {
            return false;
        }

        return $key->last_used_at->lt(now()->subDays(static::staleAfterDays()));
    }

    private static function statusOf(ApiKey $key): string
    {
        return match (true) {
            $key->isRevoked() => 'revoked',
            $key->isExpired() => 'expired',
            default => 'active',
        };
    }

    /** Whatever the form currently holds, as a type. Defaults to the safer one. */
    private static function typeFrom(mixed $value): ApiKeyType
    {
        if ($value instanceof ApiKeyType) {
            return $value;
        }

        return ApiKeyType::tryFrom((string) $value) ?? ApiKeyType::Publishable;
    }
}
