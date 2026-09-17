<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Catalog\Actions\SaveCancellationPolicy;
use App\Domain\Tenancy\Support\SetupChecklist;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\VesselResource;
use App\Models\CancellationPolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * The first-run setup guide (#51, SAA-9, SAA-10), one question at a time.
 *
 * ## One step on screen, the list beside it (product owner, 2026-09-17)
 *
 * Direction B of the onboarding mockups: a short list of the six steps on the
 * left, and on the right the one step being answered, with «Αργότερα», «Πίσω»
 * and «Συνέχεια». It replaced a Filament wizard whose step bar, per-step skip
 * links and hand-off buttons read as a lot to take in on a first afternoon.
 *
 * ## Two kinds of step, and why they are not the same kind
 *
 * Three steps already have a screen that does the job properly. Branding has
 * {@see Branding} — logo processing, the sanitisers, the contrast check from
 * #17. A boat has {@see VesselResource} and a trip has {@see ProductResource},
 * with CAT-5's validation and the publish checklist inside them. Reimplementing
 * them here would produce a second, worse version of a form that already
 * exists. So those steps **send the operator to the screen that owns the job**
 * and tick themselves from the data when they come back.
 *
 * The business details, the VAT default and the cancellation policy are asked
 * here. The policy is offered as three ready ladders because a new operator
 * does not yet think in tiers; it is saved through {@see SaveCancellationPolicy}
 * like any other, becomes the default, and is edited later on its own screen.
 *
 * ## Every step writes as it goes
 *
 * #51: *"nothing is held in session until a final submit"*. «Συνέχεια» saves the
 * step it is on. An operator who fills in their ΑΦΜ and closes the tab has
 * filled in their ΑΦΜ.
 *
 * ## Skipping is a requirement, not a courtesy
 *
 * SAA-10: an operator whose accountant has not answered the VAT question must
 * still reach the panel and add their boats. «Αργότερα» records the skip and
 * moves on. Nothing on this page blocks the panel.
 *
 * ## Owner only, and off when /admin says so
 *
 * TEN-8: *crew never see the wizard*. `canAccess()` keeps the item out of the
 * navigation, `mount()` closes a link that was open when a role changed, and
 * each write re-checks. The platform can switch the guide off per operator
 * (`Tenant::usesSetupGuide()`), which {@see SetupChecklist::applies()} reads.
 */
class Setup extends Page implements HasForms
{
    use InteractsWithForms;

    /** The three ready ladders, in the order they are offered. */
    public const PRESETS = ['flexible', 'standard', 'strict'];

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static string $view = 'filament.app.pages.setup';

    /**
     * First in the menu, above every group, while there is setting up left.
     *
     * Filament renders ungrouped items before grouped ones, so having no group
     * is what puts this at the top.
     */
    protected static ?int $navigationSort = -100;

    /** @var array<string, mixed> */
    public array $data = [];

    /** The step on screen; empty means "where the operator left off". */
    #[Url(as: 'step')]
    public ?string $step = null;

    /** The ladder chosen on the cancellation step. */
    public string $policyPreset = 'standard';

    /** None, so it sits above the three groups rather than inside one. */
    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    /**
     * Gone from the menu the moment the guide is finished or switched off.
     *
     * **The URL keeps working**: this is the only screen that asks for the
     * business details in one place, so what disappears is the invitation, not
     * the page.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && SetupChecklist::applies();
    }

    public static function getNavigationLabel(): string
    {
        return __('setup.nav');
    }

    /** How many steps are still outstanding, on the navigation item itself. */
    public static function getNavigationBadge(): ?string
    {
        if (! SetupChecklist::applies()) {
            return null;
        }

        $progress = SetupChecklist::progress();
        $left = $progress['total'] - $progress['done'];

        return $left > 0 ? (string) $left : null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('setup.title');
    }

    public function getSubheading(): ?string
    {
        return __('setup.subtitle');
    }

    /**
     * Owner only, and deliberately not through a policy.
     *
     * The record here **is** the tenant, and `TenantPolicy::update` answers
     * `isSuperAdmin()`. `ManageBilling` is the owner's capability (TEN-8), and
     * what this page writes is the same kind of thing as the money settings.
     */
    public static function canAccess(): bool
    {
        if (! Tenancy::check()) {
            return false;
        }

        $user = auth()->user();

        return $user instanceof User && $user->hasCapability(Capability::ManageBilling);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $tenant = $this->tenant();

        $this->getForm('form')?->fill([
            'legal_name' => $tenant->legal_name,
            'vat_number' => $tenant->vat_number,
            'tax_office' => $tenant->tax_office,
            'address_line1' => $tenant->address_line1,
            'city' => $tenant->city,
            'postcode' => $tenant->postcode,
            'phone' => $tenant->phone,
            'default_vat_rate_id' => $tenant->default_vat_rate_id,
        ]);
    }

    /**
     * Every field of every step, each shown only on its own step.
     *
     * One form rather than one per step, so `data.*` keeps the whole account's
     * answers and «Πίσω» shows what was typed.
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(2)
                    ->visible(fn (): bool => $this->currentStep() === SetupChecklist::BUSINESS)
                    ->schema([
                        TextInput::make('legal_name')
                            ->label(__('setup.fields.legal_name.label'))
                            ->helperText(__('setup.fields.legal_name.help'))
                            ->maxLength(180)
                            ->columnSpanFull(),

                        TextInput::make('vat_number')
                            ->label(__('setup.fields.vat_number.label'))
                            ->helperText(__('setup.fields.vat_number.help'))
                            ->maxLength(20),

                        TextInput::make('tax_office')
                            ->label(__('setup.fields.tax_office.label'))
                            ->maxLength(60),

                        TextInput::make('address_line1')
                            ->label(__('setup.fields.address_line1.label'))
                            ->maxLength(180)
                            ->columnSpanFull(),

                        TextInput::make('city')
                            ->label(__('setup.fields.city.label'))
                            ->maxLength(80),

                        TextInput::make('postcode')
                            ->label(__('setup.fields.postcode.label'))
                            ->maxLength(16),

                        TextInput::make('phone')
                            ->label(__('setup.fields.phone.label'))
                            ->tel()
                            ->maxLength(32),
                    ]),

                // Nothing is `required()`, on purpose: a required field in a
                // skippable guide is an error the operator cannot clear.
                Select::make('default_vat_rate_id')
                    ->label(__('setup.fields.default_vat_rate.label'))
                    ->helperText(__('setup.fields.default_vat_rate.help'))
                    ->options(fn (): array => $this->vatOptions())
                    ->searchable()
                    ->native(false)
                    ->visible(fn (): bool => $this->currentStep() === SetupChecklist::VAT),
            ])
            ->statePath('data');
    }

    /* -----------------------------------------------------------------
     | Moving between steps
     ----------------------------------------------------------------- */

    /**
     * The step on screen: the one asked for, or where the operator left off,
     * or the closing screen once nothing is left.
     */
    public function currentStep(): string
    {
        if (is_string($this->step) && in_array($this->step, SetupChecklist::steps(), true)) {
            return $this->step;
        }

        return SetupChecklist::next() ?? SetupChecklist::READY;
    }

    /** Save the step on screen and move to the next one still open. */
    public function continue(): void
    {
        $current = $this->currentStep();

        match ($current) {
            SetupChecklist::BUSINESS => $this->persistBusiness(),
            SetupChecklist::VAT => $this->persistVat(),
            SetupChecklist::CANCELLATION => $this->persistCancellation(),
            default => $this->guardedTenant(),
        };

        // Answered now, so no longer set aside.
        if ($this->isDone($current) && $this->isSkipped($current)) {
            $this->unskip($current);
        }

        $this->step = $this->stepAfter($current);
    }

    /** «Αργότερα»: set this step aside and move on. */
    public function later(): void
    {
        $current = $this->currentStep();

        if ($current !== SetupChecklist::READY && ! $this->isDone($current)) {
            $this->skip($current);
        }

        $this->step = $this->stepAfter($current);
    }

    public function back(): void
    {
        $steps = SetupChecklist::steps();
        $index = array_search($this->currentStep(), $steps, true);

        $this->step = $steps[max(0, (int) $index - 1)];
    }

    public function goTo(string $step): void
    {
        if (in_array($step, SetupChecklist::steps(), true)) {
            $this->step = $step;
        }
    }

    public function choosePreset(string $preset): void
    {
        if (in_array($preset, self::PRESETS, true)) {
            $this->policyPreset = $preset;
        }
    }

    /**
     * The next step after `$step` that is neither done nor set aside, or the
     * closing screen. Forward only: «Συνέχεια» never sends somebody back up
     * the list to a step they skipped a minute ago.
     */
    private function stepAfter(string $step): string
    {
        $steps = SetupChecklist::questions();
        $index = array_search($step, $steps, true);
        $state = SetupChecklist::state();
        $skipped = SetupChecklist::skipped();

        foreach (array_slice($steps, $index === false ? count($steps) : $index + 1) as $candidate) {
            if (! ($state[$candidate] ?? false) && ! in_array($candidate, $skipped, true)) {
                return $candidate;
            }
        }

        return SetupChecklist::READY;
    }

    /* -----------------------------------------------------------------
     | Writes
     ----------------------------------------------------------------- */

    /**
     * «Τέλος». Writes `onboarding_completed_at`, the one thing on this page that
     * cannot be derived, and what stops the home-page checklist.
     */
    public function finish(): void
    {
        $tenant = $this->guardedTenant();

        // Whatever is filled in, rather than only the step on screen.
        $this->persistBusiness();
        $this->persistVat();

        $tenant->forceFill(['onboarding_completed_at' => Carbon::now()])->save();

        Notification::make()
            ->title(__('setup.finished.title'))
            ->body(__('setup.finished.body'))
            ->success()
            ->send();

        $this->redirect(Dashboard::getUrl());
    }

    private function persistBusiness(): void
    {
        $tenant = $this->guardedTenant();
        $state = $this->data;

        $tenant->forceFill([
            // Trimmed to null rather than left as `''`: an empty string is a
            // legal name as far as `filled()` is concerned.
            'legal_name' => $this->normalise($state['legal_name'] ?? null),
            'vat_number' => $this->normalise($state['vat_number'] ?? null),
            'tax_office' => $this->normalise($state['tax_office'] ?? null),
            'address_line1' => $this->normalise($state['address_line1'] ?? null),
            'city' => $this->normalise($state['city'] ?? null),
            'postcode' => $this->normalise($state['postcode'] ?? null),
            'phone' => $this->normalise($state['phone'] ?? null),
        ])->save();
    }

    private function persistVat(): void
    {
        $tenant = $this->guardedTenant();

        $chosen = $this->data['default_vat_rate_id'] ?? null;

        // Checked against the list the select was built from: this is a
        // Livewire payload, and a withdrawn rate would otherwise pre-fill
        // every product this operator creates.
        $valid = $chosen !== null && $chosen !== '' && array_key_exists((int) $chosen, $this->vatOptions());

        $tenant->forceFill([
            'default_vat_rate_id' => $valid ? (int) $chosen : null,
        ])->save();
    }

    /**
     * The chosen ladder, once. An operator who already has a policy keeps it:
     * this step only ever adds the first one.
     */
    private function persistCancellation(): void
    {
        $this->guardedTenant();

        if (CancellationPolicy::query()->exists()) {
            return;
        }

        $preset = in_array($this->policyPreset, self::PRESETS, true) ? $this->policyPreset : 'standard';
        $ladder = self::presetLadder($preset);

        app(SaveCancellationPolicy::class)(
            new CancellationPolicy,
            [
                'name' => [
                    'el' => __('setup.policy.' . $preset . '.name', locale: 'el'),
                    'en' => __('setup.policy.' . $preset . '.name', locale: 'en'),
                ],
                'free_cancellation_hours' => $ladder['free_cancellation_hours'],
                'is_default' => true,
            ],
            $ladder['tiers'],
        );
    }

    /**
     * What each ready ladder means, as the policy columns store it.
     *
     * @return array{free_cancellation_hours: int|null, tiers: list<array{days_before: int, refund_percent: int}>}
     */
    public static function presetLadder(string $preset): array
    {
        return match ($preset) {
            'flexible' => ['free_cancellation_hours' => 24, 'tiers' => []],
            'strict' => ['free_cancellation_hours' => null, 'tiers' => [
                ['days_before' => 14, 'refund_percent' => 50],
            ]],
            default => ['free_cancellation_hours' => null, 'tiers' => [
                ['days_before' => 7, 'refund_percent' => 100],
                ['days_before' => 2, 'refund_percent' => 50],
            ]],
        };
    }

    public function skip(string $step): void
    {
        $tenant = $this->guardedTenant();

        if (! in_array($step, SetupChecklist::steps(), true)) {
            return;
        }

        $skipped = SetupChecklist::skipped($tenant);
        $skipped[] = $step;

        $tenant->forceFill([
            'onboarding_skipped_steps' => array_values(array_unique($skipped)),
        ])->save();
    }

    public function unskip(string $step): void
    {
        $tenant = $this->guardedTenant();

        $skipped = array_values(array_diff(SetupChecklist::skipped($tenant), [$step]));

        $tenant->forceFill([
            'onboarding_skipped_steps' => $skipped === [] ? null : $skipped,
        ])->save();
    }

    /* -----------------------------------------------------------------
     | Reading, for the view
     ----------------------------------------------------------------- */

    /** @return array<string, bool> */
    public function stepStates(): array
    {
        return SetupChecklist::state();
    }

    /** @return list<string> */
    public function questions(): array
    {
        return SetupChecklist::questions();
    }

    /** Where a step whose work lives on another screen sends the operator. */
    public function handOffUrl(string $step): ?string
    {
        return match ($step) {
            SetupChecklist::BRANDING => Branding::getUrl(),
            SetupChecklist::VESSEL => VesselResource::getUrl('create'),
            SetupChecklist::PRODUCT => ProductResource::getUrl('create'),
            default => null,
        };
    }

    public function isDone(string $step): bool
    {
        return SetupChecklist::state()[$step] ?? false;
    }

    public function isSkipped(string $step): bool
    {
        return in_array($step, SetupChecklist::skipped(), true);
    }

    /** The operator's existing policy name, when the step is already answered. */
    public function existingPolicyName(): ?string
    {
        $policy = CancellationPolicy::query()->where('is_default', true)->first()
            ?? CancellationPolicy::query()->first();

        return $policy instanceof CancellationPolicy ? (string) $policy->name : null;
    }

    /** The steps still open, named, for the closing screen. */
    public function outstanding(): string
    {
        $open = [];

        foreach (SetupChecklist::questions() as $step) {
            if (! $this->isDone($step)) {
                $open[] = __('setup.steps.' . $step . '.label');
            }
        }

        return $open === []
            ? ''
            : __('setup.steps.ready.outstanding', ['steps' => implode(', ', $open)]);
    }

    /**
     * The rates an operator may pick, from the one place that decides
     * ({@see ProductResource::vatRateOptions()}).
     *
     * @return array<int, string>
     */
    private function vatOptions(): array
    {
        return ProductResource::vatRateOptions();
    }

    private function normalise(mixed $value): ?string
    {
        $trimmed = trim(is_scalar($value) ? (string) $value : '');

        return $trimmed === '' ? null : $trimmed;
    }

    private function tenant(): Tenant
    {
        $tenant = Tenancy::check() ? Tenancy::current() : null;

        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    /**
     * The tenant, with the write re-checked: a Livewire action arrives as its
     * own request, after `canAccess()` and TEN-9 have run for the page.
     */
    private function guardedTenant(): Tenant
    {
        $tenant = $this->tenant();

        abort_unless(static::canAccess(), 403);

        // TEN-9: a lapsed subscription opens the panel and writes nothing.
        abort_unless($tenant->allowsWrites(), 403);

        return $tenant;
    }
}
