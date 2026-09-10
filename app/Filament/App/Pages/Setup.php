<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Tenancy\Support\SetupChecklist;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\VesselResource;
use App\Filament\App\Widgets\SetupProgress;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Tenancy;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * The first-run setup guide (#51, SAA-9, SAA-10).
 *
 * ## Two kinds of step, and why they are not the same kind
 *
 * SAA-9 lists six things, and three of them already have a screen that does the
 * job properly. Branding has {@see Branding} — logo processing, the sanitisers,
 * the contrast check from #17. A boat has {@see VesselResource} and a trip has
 * {@see ProductResource}, with CAT-5's validation and the publish checklist
 * inside them. Reimplementing any of those three inside a wizard step would
 * produce a second, worse version of a form that already exists, and the day
 * one of them gains a field is the day the wizard starts writing an incomplete
 * record.
 *
 * So those three steps **send the operator to the screen that owns the job**
 * and tick themselves from the data when it comes back. The two steps with no
 * screen anywhere — the business details and the VAT default — are asked here,
 * because here is the only place they are asked at all.
 *
 * ## Every step writes as it goes
 *
 * #51 says so outright: *"nothing is held in session until a final submit"*.
 * An operator who fills in their ΑΦΜ and then closes the tab has filled in their
 * ΑΦΜ. `afterValidation` on each step is where that happens — it runs when the
 * operator moves forward, which is the moment they have said the step is
 * answered.
 *
 * ## Skipping is a requirement, not a courtesy
 *
 * SAA-10, and the reason is concrete: an operator whose accountant has not
 * answered the VAT question must still reach the panel and add their boats.
 * Every step carries a «Παράλειψη» that records the skip and moves on. Nothing
 * on this page blocks the panel — there is no modal, and the URL is reachable or
 * ignorable at will.
 *
 * ## Owner only
 *
 * TEN-8, and #51's last acceptance criterion says it in as many words: *crew
 * never see the wizard*. The legal identity of the business, its VAT rate and
 * its branding are an owner's to set. `canAccess()` keeps the item out of the
 * navigation, `mount()` closes a link that was open when a role changed, and
 * each write re-checks — a Livewire action is a POST somebody can craft without
 * ever loading the page.
 */
class Setup extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static string $view = 'filament.app.pages.setup';

    /**
     * First in the menu, above every group.
     *
     * Filament renders ungrouped items before grouped ones, so having no group
     * is what puts this at the top — and the top is where it belongs while it
     * is there at all. It was inside the collapsed Ρυθμίσεις group first, which
     * is the one place a new operator will not look: that group is closed by
     * default precisely because it holds the screens somebody goes looking for,
     * and this is the one screen that has to find *them*.
     */
    protected static ?int $navigationSort = -100;

    /** @var array<string, mixed> */
    public array $data = [];

    /** None, so it sits above the three groups rather than inside one. */
    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    /**
     * Gone from the menu the moment the guide is finished.
     *
     * A first-run guide that stays in the navigation for the rest of an
     * operator's life is a permanent reminder of something already done. The
     * badge going quiet is not enough — the row itself goes, along with
     * {@see SetupProgress} on the dashboard.
     *
     * **The URL keeps working**, and that is deliberate rather than an
     * oversight. This is the only screen that asks for the business details, so
     * one that refused after completion would strand them: what disappears is
     * the invitation, not the page. Everything an operator changes routinely
     * has a permanent home in Ρυθμίσεις — the VAT default is on
     * {@see PaymentSettings}, beside the other tax decision.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && SetupChecklist::applies();
    }

    public static function getNavigationLabel(): string
    {
        return __('setup.nav');
    }

    /**
     * How many steps are still outstanding, on the navigation item itself.
     *
     * Null once the wizard is finished, so an operator who has done this is not
     * left with a permanent badge on a screen they never need again.
     */
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
     * Every other screen in this panel gates on a model's policy, and this one
     * cannot: the record here **is** the tenant, and `TenantPolicy::update`
     * answers `isSuperAdmin()`. That policy guards `/admin` changing somebody
     * else's operator, which is a different question from an owner filling in
     * their own ΑΦΜ — going through it would refuse the one person this page
     * exists for.
     *
     * So the capability is asked directly, and `ManageBilling` is the right
     * one. TEN-8 puts it with the owner alone, and what this page writes — the
     * legal identity every invoice carries and the VAT rate they are issued at
     * — is the same kind of thing as the money settings it sits beside.
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

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Wizard::make([
                    $this->businessStep(),
                    $this->brandingStep(),
                    $this->vatStep(),
                    $this->vesselStep(),
                    $this->productStep(),
                    $this->readyStep(),
                ])
                    // Where the operator left off, rather than always at step
                    // one. This is the whole of SAA-10's "resumable", and it
                    // costs one call because the answer is derived rather than
                    // stored.
                    ->startOnStep($this->startStep())
                    ->persistStepInQueryString('step')
                    ->submitAction($this->finishAction()),
            ])
            ->statePath('data');
    }

    /* -----------------------------------------------------------------
     | The steps
     ----------------------------------------------------------------- */

    private function businessStep(): Wizard\Step
    {
        return Wizard\Step::make(SetupChecklist::BUSINESS)
            ->label(__('setup.steps.business.label'))
            ->description(__('setup.steps.business.description'))
            ->icon('heroicon-o-identification')
            ->completedIcon('heroicon-o-check-circle')
            ->schema([
                Placeholder::make('business_why')
                    ->hiddenLabel()
                    ->content(__('setup.steps.business.why')),

                TextInput::make('legal_name')
                    ->label(__('setup.fields.legal_name.label'))
                    ->helperText(__('setup.fields.legal_name.help'))
                    ->maxLength(180),

                TextInput::make('vat_number')
                    ->label(__('setup.fields.vat_number.label'))
                    ->helperText(__('setup.fields.vat_number.help'))
                    ->maxLength(20),

                TextInput::make('tax_office')
                    ->label(__('setup.fields.tax_office.label'))
                    ->maxLength(60),

                TextInput::make('address_line1')
                    ->label(__('setup.fields.address_line1.label'))
                    ->maxLength(180),

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

                $this->skipAction(SetupChecklist::BUSINESS),
            ])
            // Nothing is `required()`, on purpose. A required field in a
            // skippable wizard is a contradiction the operator meets as an
            // error message they cannot clear, and every one of these columns
            // is nullable because M1 made it so.
            ->afterValidation(function (): void {
                $this->persistBusiness();
            });
    }

    private function brandingStep(): Wizard\Step
    {
        return Wizard\Step::make(SetupChecklist::BRANDING)
            ->label(__('setup.steps.branding.label'))
            ->description(__('setup.steps.branding.description'))
            ->icon('heroicon-o-swatch')
            ->completedIcon('heroicon-o-check-circle')
            ->schema([
                $this->handOff(
                    step: SetupChecklist::BRANDING,
                    url: Branding::getUrl(),
                    label: __('setup.steps.branding.action'),
                ),
            ]);
    }

    private function vatStep(): Wizard\Step
    {
        return Wizard\Step::make(SetupChecklist::VAT)
            ->label(__('setup.steps.vat.label'))
            ->description(__('setup.steps.vat.description'))
            ->icon('heroicon-o-receipt-percent')
            ->completedIcon('heroicon-o-check-circle')
            ->schema([
                Placeholder::make('vat_why')
                    ->hiddenLabel()
                    ->content(__('setup.steps.vat.why')),

                Select::make('default_vat_rate_id')
                    ->label(__('setup.fields.default_vat_rate.label'))
                    ->helperText(__('setup.fields.default_vat_rate.help'))
                    ->options(fn (): array => $this->vatOptions())
                    ->searchable()
                    ->native(false),

                Placeholder::make('vat_caveat')
                    ->hiddenLabel()
                    ->content(__('setup.steps.vat.caveat')),

                $this->skipAction(SetupChecklist::VAT),
            ])
            ->afterValidation(function (): void {
                $this->persistVat();
            });
    }

    private function vesselStep(): Wizard\Step
    {
        return Wizard\Step::make(SetupChecklist::VESSEL)
            ->label(__('setup.steps.vessel.label'))
            ->description(__('setup.steps.vessel.description'))
            ->icon('heroicon-o-lifebuoy')
            ->completedIcon('heroicon-o-check-circle')
            ->schema([
                $this->handOff(
                    step: SetupChecklist::VESSEL,
                    url: VesselResource::getUrl('create'),
                    label: __('setup.steps.vessel.action'),
                ),
            ]);
    }

    private function productStep(): Wizard\Step
    {
        return Wizard\Step::make(SetupChecklist::PRODUCT)
            ->label(__('setup.steps.product.label'))
            ->description(__('setup.steps.product.description'))
            ->icon('heroicon-o-ticket')
            ->completedIcon('heroicon-o-check-circle')
            ->schema([
                $this->handOff(
                    step: SetupChecklist::PRODUCT,
                    url: ProductResource::getUrl('create'),
                    label: __('setup.steps.product.action'),
                ),
            ]);
    }

    /**
     * The hand-over: where the operator's pages are, and how to embed them.
     *
     * The only step with nothing to fill in. It is also the one that finishes
     * the wizard — see {@see finishAction()}.
     */
    private function readyStep(): Wizard\Step
    {
        return Wizard\Step::make(SetupChecklist::READY)
            ->label(__('setup.steps.ready.label'))
            ->description(__('setup.steps.ready.description'))
            ->icon('heroicon-o-flag')
            ->completedIcon('heroicon-o-check-circle')
            ->schema([
                Placeholder::make('ready_body')
                    ->hiddenLabel()
                    ->content(__('setup.steps.ready.body')),

                Placeholder::make('ready_outstanding')
                    ->hiddenLabel()
                    ->content(fn (): string => $this->outstandingSummary())
                    ->visible(fn (): bool => $this->outstandingSummary() !== ''),
            ]);
    }

    /* -----------------------------------------------------------------
     | Shared pieces
     ----------------------------------------------------------------- */

    /**
     * A step whose work belongs to another screen.
     *
     * Two states and no third: it is done, or here is the button that does it.
     * The tick is read from the data every render, so an operator who adds a
     * boat in another tab and comes back finds this step green without the
     * wizard having been told.
     */
    private function handOff(string $step, string $url, string $label): Actions
    {
        return Actions::make([
            FormAction::make('go_' . $step)
                ->label($label)
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url($url)
                ->visible(fn (): bool => ! $this->isDone($step)),

            FormAction::make('done_' . $step)
                ->label(__('setup.done'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->disabled()
                ->visible(fn (): bool => $this->isDone($step)),

            FormAction::make('skip_' . $step)
                ->label(__('setup.skip'))
                ->color('gray')
                ->link()
                ->visible(fn (): bool => ! $this->isDone($step) && ! $this->isSkipped($step))
                ->action(fn () => $this->skip($step)),

            FormAction::make('unskip_' . $step)
                ->label(__('setup.unskip'))
                ->color('gray')
                ->link()
                ->visible(fn (): bool => $this->isSkipped($step))
                ->action(fn () => $this->unskip($step)),
        ]);
    }

    /** The «Παράλειψη» link on a step that is filled in here rather than elsewhere. */
    private function skipAction(string $step): Actions
    {
        return Actions::make([
            FormAction::make('skip_' . $step)
                ->label(__('setup.skip'))
                ->color('gray')
                ->link()
                ->visible(fn (): bool => ! $this->isSkipped($step))
                ->action(fn () => $this->skip($step)),

            FormAction::make('unskip_' . $step)
                ->label(__('setup.unskip'))
                ->color('gray')
                ->link()
                ->visible(fn (): bool => $this->isSkipped($step))
                ->action(fn () => $this->unskip($step)),
        ]);
    }

    /**
     * The last button.
     *
     * It writes `onboarding_completed_at`, which is the one thing on this page
     * that cannot be derived, and is what stops the dashboard checklist.
     */
    private function finishAction(): FormAction
    {
        return FormAction::make('finish')
            ->label(__('setup.finish'))
            ->icon('heroicon-o-check')
            ->submit('finish');
    }

    /* -----------------------------------------------------------------
     | Writes
     ----------------------------------------------------------------- */

    public function finish(): void
    {
        $tenant = $this->guardedTenant();

        // Whatever is on the last screen the operator filled in, rather than
        // only the step they happen to be standing on. Moving forward already
        // wrote each one; this catches the case where they edited a field and
        // pressed the final button without stepping through again.
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
        $state = (array) $this->getForm('form')?->getRawState();

        $tenant->forceFill([
            // Trimmed to null rather than left as `''`. An empty string is a
            // legal name as far as `filled()` is concerned, and the checklist
            // would tick a step nobody answered.
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
        $state = (array) $this->getForm('form')?->getRawState();

        $chosen = $state['default_vat_rate_id'] ?? null;

        // Checked against the same list the select was built from rather than
        // saved as sent. This is a Livewire payload, and an id from a withdrawn
        // or platform-wide-invalid row would otherwise pre-fill every product
        // this operator creates.
        $valid = $chosen !== null && array_key_exists((int) $chosen, $this->vatOptions());

        $tenant->forceFill([
            'default_vat_rate_id' => $valid ? (int) $chosen : null,
        ])->save();
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
     | Reading
     ----------------------------------------------------------------- */

    /**
     * The rates an operator may pick, from the one place that decides.
     *
     * {@see ProductResource::vatRateOptions()} rather than a query of its own.
     * This is the same question the product form asks — *which rates may this
     * operator choose from* — and two implementations of it would eventually be
     * two answers: the day a withdrawn rate stops appearing in one list is the
     * day it is still pre-filling every new product from the other.
     *
     * The label is that method's, code plus percentage, and carries no opinion
     * about which rate applies. CAT-11b makes that an accountant's answer and
     * forbids any code, seeder or fixture presenting a percentage as
     * authoritative.
     *
     * @return array<int, string>
     */
    private function vatOptions(): array
    {
        return ProductResource::vatRateOptions();
    }

    private function isDone(string $step): bool
    {
        return SetupChecklist::state()[$step] ?? false;
    }

    private function isSkipped(string $step): bool
    {
        return in_array($step, SetupChecklist::skipped(), true);
    }

    /**
     * Which step to open on.
     *
     * `Wizard::startOnStep()` counts from one, and `next()` answers a key. A
     * finished checklist opens on the last step, which is the hand-over — the
     * right screen for somebody who came back to copy the embed snippet again.
     */
    private function startStep(): int
    {
        $next = SetupChecklist::next();
        $steps = SetupChecklist::steps();

        if ($next === null) {
            return count($steps);
        }

        $index = array_search($next, $steps, true);

        return $index === false ? 1 : $index + 1;
    }

    /**
     * What is still open, named, on the last screen.
     *
     * An operator finishing with two steps skipped should be told which two
     * rather than be congratulated as if they were done. Empty when nothing is
     * outstanding, and the placeholder hides itself.
     */
    private function outstandingSummary(): string
    {
        $open = [];

        foreach (SetupChecklist::state() as $step => $done) {
            if (! $done && $step !== SetupChecklist::READY) {
                $open[] = __('setup.steps.' . $step . '.label');
            }
        }

        return $open === []
            ? ''
            : __('setup.steps.ready.outstanding', ['steps' => implode(', ', $open)]);
    }

    private function normalise(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function tenant(): Tenant
    {
        $tenant = Tenancy::check() ? Tenancy::current() : null;

        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    /**
     * The tenant, with the write re-checked.
     *
     * Every write on this page goes through here. `canAccess()` guards the
     * render and TEN-9 guards the subscription, and neither has run when a
     * Livewire action arrives as its own request.
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
