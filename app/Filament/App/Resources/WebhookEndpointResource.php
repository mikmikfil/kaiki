<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Webhooks\EventRegistry;
use App\Domain\Webhooks\Support\SafeUrl;
use App\Enums\WebhookEvent;
use App\Filament\App\Resources\WebhookEndpointResource\Pages;
use App\Filament\App\Resources\WebhookEndpointResource\RelationManagers\DeliveriesRelationManager;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookEndpoint;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Where an operator points Kaiki at their own systems (spec OPS-19, OPS-20).
 *
 * Thin by design (CNV-5). Nothing here knows how a delivery is signed, retried
 * or given up on — that is `DispatchWebhookEvent` and `DeliverWebhook`. This
 * class decides what an operator sees and what they may press.
 *
 * ## The secret is shown once, and this screen is `ApiKeyResource`'s twin
 *
 * Same problem, same shape, deliberately: a credential that exists in plaintext
 * for exactly one response. The difference is that an API key is *hashed* and
 * this one is *encrypted*, because signing is symmetric and the platform has to
 * read it back on every delivery — so "shown once" here is a discipline rather
 * than a mathematical fact, and the rotate action is what makes a leaked secret
 * recoverable.
 *
 * ## The URL is validated twice, and neither check is the real one
 *
 * The form refuses a non-HTTPS or private-range URL so an operator finds out
 * while they are looking at the field. It skips DNS, because a form must answer
 * instantly. The check that matters runs in {@see DeliverWebhook},
 * immediately before the socket, where a hostname that has been repointed at
 * `169.254.169.254` since save time is caught.
 */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    /** Beside the API keys: both are how somebody else's software reaches this one. */
    protected static ?int $navigationSort = 32;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('webhooks.nav');
    }

    public static function getModelLabel(): string
    {
        return __('webhooks.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('webhooks.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            TextInput::make('name')
                ->label(__('webhooks.form.name.label'))
                ->helperText(__('webhooks.form.name.help'))
                ->required()
                ->maxLength(80),

            TextInput::make('url')
                ->label(__('webhooks.form.url.label'))
                ->helperText(__('webhooks.form.url.help'))
                ->required()
                ->maxLength(500)
                ->rules([
                    static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        // `resolve: false` — a form field must answer while
                        // somebody is typing, and a DNS lookup on every
                        // keystroke is a form that hangs. The delivery job does
                        // the resolving check.
                        if (! is_string($value) || ! SafeUrl::isAllowed($value, resolve: false)) {
                            $fail(__('webhooks.form.url.refused'));
                        }
                    },
                ]),

            CheckboxList::make('events')
                ->label(__('webhooks.form.events.label'))
                ->helperText(__('webhooks.form.events.help'))
                ->options(WebhookEvent::options())
                ->descriptions(self::eventDescriptions())
                ->required()
                ->columns(1)
                // The registry is the validation, so a name that is not one of
                // the four cannot arrive however the request was made.
                ->in(EventRegistry::names()),

            Toggle::make('is_active')
                ->label(__('webhooks.form.is_active.label'))
                ->helperText(__('webhooks.form.is_active.help'))
                ->default(true),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('webhooks.table.name'))
                    ->searchable(),

                TextColumn::make('url')
                    ->label(__('webhooks.table.url'))
                    ->limit(48)
                    ->tooltip(fn (WebhookEndpoint $record): string => $record->url),

                TextColumn::make('events')
                    ->label(__('webhooks.table.events'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => $state),

                IconColumn::make('is_active')
                    ->label(__('webhooks.table.is_active'))
                    ->boolean(),

                TextColumn::make('consecutive_failures')
                    ->label(__('webhooks.table.failures'))
                    ->badge()
                    // Grey at zero rather than green: "no failures" is the
                    // ordinary state, and a screen of green ticks trains an
                    // operator to stop reading the column.
                    ->color(fn (int $state): string => $state === 0 ? 'gray' : 'danger'),

                TextColumn::make('last_delivery_at')
                    ->label(__('webhooks.table.last_delivery'))
                    ->dateTime()
                    ->placeholder(__('webhooks.table.never'))
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('webhooks.empty.heading'))
            ->emptyStateDescription(__('webhooks.empty.description'));
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [DeliveriesRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebhookEndpoints::route('/'),
            'edit' => Pages\EditWebhookEndpoint::route('/{record}/edit'),
        ];
    }

    /**
     * A sentence per event, because the names are not self-explanatory.
     *
     * `guest_details.completed` in particular means something specific — every
     * passenger has the fields *this operator* asked for — and an operator
     * subscribing to it should know it carries counts and never documents.
     *
     * @return array<string, string>
     */
    private static function eventDescriptions(): array
    {
        // The whole block, then indexed — never `__('webhooks.events.' . $value)`.
        //
        // Every event name contains a dot, and Laravel reads a dot in a
        // translation key as a path separator: the direct lookup searches for
        // `webhooks → events → booking → confirmed`, finds nothing, and returns
        // the key itself. The form then shows `webhooks.events.booking.confirmed`
        // under the checkbox, which is what it did until somebody opened the
        // screen. `HasTranslatedLabel::line()` carries the same note for the
        // same reason — this is the second time the trap has been walked into.
        /** @var array<string, string> $block */
        $block = (array) trans('webhooks.events');

        $descriptions = [];

        foreach (WebhookEvent::cases() as $event) {
            $descriptions[$event->value] = $block[$event->value] ?? '';
        }

        return $descriptions;
    }
}
