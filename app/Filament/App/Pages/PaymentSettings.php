<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Booking\Actions\ComputeBalanceDueAt;
use App\Filament\App\Resources\ProductResource;
use App\Models\IntegrationCredential;
use App\Support\Tenancy;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * How much of the money is taken at booking (spec PRC-23, PRC-24, PRC-27).
 *
 * ## The rate plan knew how much; nobody could say whether
 *
 * `rate_plans.deposit_type` has existed since #21, so a plan could ask for 30%
 * and an operator had no way to say "not on my boat" other than setting `none`
 * on every plan one at a time — a business decision expressed as a chore. This
 * screen is the decision, once, for the operator.
 *
 * Off for everyone until it is switched on. Partial payment is not one setting,
 * it is a chain: a balance falls due (PRC-27), a reminder goes out, a guest has
 * to come back and pay it, and somebody has to chase whoever does not. Nobody
 * should be opted into that by a column default.
 *
 * ## The due date is on the same screen, because it is the same decision
 *
 * Switching deposits on without saying when the rest is due leaves a balance
 * with no date on it. {@see ComputeBalanceDueAt}
 * already falls back from the rate plan to the tenant to a config default, and
 * this is the tenant half — shown only once deposits are on, because until then
 * there is no balance for it to describe.
 *
 * ## Owner only, checked twice
 *
 * The same gate as the gateway credentials screen: TEN-8 makes money settings
 * an owner's, and crew reach neither. A page has no model for Filament to find
 * a policy on and falls back to allowing, so `canAccess()` closes that and
 * `mount()` closes it again for a link that was already open when a role
 * changed. `save()` re-checks, because a Livewire action is a POST somebody can
 * craft without ever loading the page.
 */
class PaymentSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 94;

    protected static string $view = 'filament.app.pages.payment-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('payment_settings.nav');
    }

    public function getTitle(): string
    {
        return __('payment_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('payment_settings.subtitle');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', IntegrationCredential::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $tenant = Tenancy::current();

        $this->getForm('form')?->fill([
            'deposits_enabled' => (bool) ($tenant->deposits_enabled ?? false),
            'balance_due_days_before_departure' => $tenant?->balance_due_days_before_departure,
            'default_vat_rate_id' => $tenant?->default_vat_rate_id,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('payment_settings.sections.deposits'))
                ->description(__('payment_settings.sections.deposits_help'))
                ->schema([
                    Toggle::make('deposits_enabled')
                        ->label(__('payment_settings.fields.deposits_enabled.label'))
                        ->helperText(__('payment_settings.fields.deposits_enabled.help'))
                        // Live, so the due-date field appears the moment it is
                        // switched on rather than after a save — the operator
                        // sees the second half of the decision while making the
                        // first.
                        ->live(),

                    TextInput::make('balance_due_days_before_departure')
                        ->label(__('payment_settings.fields.balance_days.label'))
                        ->helperText(__('payment_settings.fields.balance_days.help'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(365)
                        ->suffix(__('payment_settings.fields.balance_days.suffix'))
                        // ADR-0018's platform default, shown as the placeholder
                        // so an empty field reads as «14» rather than as nothing.
                        ->placeholder((string) ComputeBalanceDueAt::PLATFORM_DEFAULT_DAYS)
                        ->visible(fn (Get $get): bool => (bool) $get('deposits_enabled')),
                ]),

            /*
             * The VAT default's permanent home (#51, CAT-11).
             *
             * The setup guide asks for it once and then leaves the navigation
             * for good, so a setting that lived only there would be one an
             * operator could never revisit. It belongs here anyway: this screen
             * is already the owner-only one about what a guest is charged, and
             * the rate is the other half of that.
             *
             * Per-product still wins, and must — a cruise is passenger
             * transport and a barbecue extra is catering (data-model §2.3).
             * This is only what a new product starts on.
             */
            Section::make(__('payment_settings.sections.vat'))
                ->description(__('payment_settings.sections.vat_help'))
                ->schema([
                    Select::make('default_vat_rate_id')
                        ->label(__('payment_settings.fields.default_vat_rate.label'))
                        ->helperText(__('payment_settings.fields.default_vat_rate.help'))
                        // The same list the product form offers, from the one
                        // place that decides which rates an operator may pick.
                        ->options(ProductResource::vatRateOptions(...))
                        ->searchable()
                        ->native(false),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $tenant = Tenancy::current();

        abort_unless($tenant !== null, 403);

        // TEN-9: a lapsed subscription opens the panel and writes nothing.
        abort_unless(Gate::allows('create', IntegrationCredential::class), 403);

        $state = (array) $this->getForm('form')?->getState();

        $enabled = (bool) ($state['deposits_enabled'] ?? false);
        $days = $state['balance_due_days_before_departure'] ?? null;
        $rate = $state['default_vat_rate_id'] ?? null;

        // Checked against the list the select was built from rather than saved
        // as sent. A Livewire payload is something a person can craft, and an
        // id for a withdrawn rate would pre-fill every product from then on.
        $rateIsSelectable = $rate !== null
            && array_key_exists((int) $rate, ProductResource::vatRateOptions());

        $tenant->forceFill([
            'deposits_enabled' => $enabled,
            // Cleared when deposits go off, rather than left behind to reappear
            // with a number nobody remembers choosing if they are switched on
            // again. Null falls through to the config default (PRC-27).
            'balance_due_days_before_departure' => $enabled && $days !== null && $days !== ''
                ? (int) $days
                : null,
            'default_vat_rate_id' => $rateIsSelectable ? (int) $rate : null,
        ])->save();

        Notification::make()
            ->title(__('payment_settings.saved'))
            ->success()
            ->send();
    }
}
