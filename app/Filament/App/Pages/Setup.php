<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Catalog\Actions\SaveCancellationPolicy;
use App\Domain\Tenancy\Support\SetupChecklist;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\VesselResource;
use App\Http\Middleware\RequireSetupFirst;
use App\Models\CancellationPolicy;
use App\Models\PolicyTemplate;
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
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 * A boat has {@see VesselResource} and a trip has {@see ProductResource}, with
 * CAT-5's validation and the publish checklist inside them. Reimplementing
 * them here would produce a second, worse version of a form that already
 * exists. So those left the guide, and the home page step **sends the operator
 * to the screen that owns the job** and ticks itself from the data.
 *
 * ## No logo and colours any more (Mike, 2026-09-25)
 *
 * *«Την επιλογή χρωμάτων και logo για τον κάθε merchant την θέλω στο admin.
 * Αφαίρεσέ την από το first time conf.»* The platform sets them when it takes
 * the operator on, on Create / Edit Merchant; the operator changes them later
 * on {@see Branding}, which is unchanged.
 *
 * The business details, the VAT default and the cancellation policy are asked
 * here. The policy is offered as ready ladders because a new operator does not
 * yet think in tiers; it is saved through {@see SaveCancellationPolicy} like any
 * other, becomes the default, and is edited later on its own screen. Which
 * ladders are offered is the platform's to decide and lives in
 * {@see PolicyTemplate} — maintained in `/admin` since 2026-09-23, rather than
 * in a `const` here and a lang file over there.
 *
 * ## Every step writes as it goes
 *
 * #51: *"nothing is held in session until a final submit"*. «Συνέχεια» saves the
 * step it is on. An operator who fills in their ΑΦΜ and closes the tab has
 * filled in their ΑΦΜ.
 *
 * ## It holds the panel back, and it hands it over on request
 *
 * Since 2026-09-22 this is the first thing a new operator sees and, until they
 * are done with it, the only thing: {@see RequireSetupFirst}
 * sends every other panel page back here, and the layout drops the navigation
 * so there is nothing to wander off into.
 *
 * That is only defensible because the way out is on the screen. **«Θα το κάνω
 * αργότερα»** (`deferSetup`) opens the panel and leaves the guide in the menu;
 * **«Δεν το χρειάζομαι»** (`dismissSetup`) retires it altogether and leaves it
 * reachable from Ρυθμίσεις. SAA-10's operator — the one whose accountant has
 * not answered the VAT question — presses either and gets on with their boats,
 * which is what that requirement was protecting.
 *
 * Per-step skipping stays what it was: «Αργότερα» records the skip and moves
 * on, so a step set aside is not the same as a step untouched.
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

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static string $view = 'filament.app.pages.setup';

    /**
     * **Full screen, with no panel around it** (product owner, 2026-09-22).
     *
     * The simple layout is the one the sign-in page uses: no sidebar, no
     * navigation, nothing to wander off into — which is the whole point of a
     * screen an operator is meant to finish. It is the same reasoning as the
     * gate in {@see RequireSetupFirst}, made visible: the
     * panel is not there yet, so the page should not pretend it is.
     *
     * The page stays a navigable `Page` rather than becoming a `SimplePage`,
     * because it has to keep its place in the menu for the operator who defers
     * it. Only the shell changes; `getLayoutData()` below supplies what that
     * shell reads.
     */
    protected static string $layout = 'filament-panels::components.layout.simple';

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

    /**
     * The template chosen on the cancellation step, by `code`.
     *
     * Empty until the step is reached, and then whatever the platform put
     * first — there is no hard-coded «standard» any more, because the admin can
     * retire or reorder every row. {@see self::defaultPreset()}.
     */
    public string $policyPreset = '';

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

    /**
     * What the simple layout needs, since this is not a `SimplePage`.
     *
     * The card is as wide as the widest step (the list beside the question),
     * and the top bar stays: it carries the user menu, and «Αποσύνδεση» has to
     * be reachable from behind a gate.
     *
     * @return array<string, mixed>
     */
    protected function getLayoutData(): array
    {
        return [
            'hasTopbar' => true,
            'maxWidth' => MaxWidth::SevenExtraLarge,
        ];
    }

    /**
     * The Kaiki mark, top left (product owner, 2026-09-22).
     *
     * With the navigation gone there is nothing on the screen that says whose
     * product this is — and this is the first screen a new operator ever sees.
     * The simple layout renders the panel's own brand logo when the page asks
     * for one, which is the platform's mark and falls back to its name.
     */
    public function hasLogo(): bool
    {
        return true;
    }

    public function getTitle(): string|Htmlable
    {
        return __('setup.title');
    }

    /**
     * No heading and no subheading above the card.
     *
     * The card carries «Βήμα 1 από 7» and the question itself, so a title above
     * it repeated the same thing in bigger type — and on a 1080p screen those
     * two blocks were what pushed the first step's fields below the fold
     * (product owner, 2026-09-22). The subtitle moved into the top row beside
     * the two exits; `getTitle()` still names the browser tab.
     */
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
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

                        // Half a row, not a whole one: five rows of fields did
                        // not fit a 1080p screen, and this is the step an
                        // operator meets first (2026-09-22). A street name does
                        // not need the width.
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

    /**
     * «Θα το κάνω αργότερα»: hand over the panel, keep the guide (2026-09-22).
     *
     * The escape hatch that makes the gate safe — *"but there should be a
     * button to totally skip it if operator wants to"*. This is the softer of
     * the two: whatever is filled in is saved, the panel opens, and the guide
     * stays first in the menu with its badge, to be picked up where it was
     * left. {@see RequireSetupFirst} never holds this
     * operator again.
     */
    public function deferSetup(): void
    {
        $tenant = $this->guardedTenant();

        // The step on screen may be half filled in. Saving first means «later»
        // never costs the operator what they had already typed.
        $this->persistBusiness();
        $this->persistVat();

        $tenant->forceFill(['onboarding_deferred_at' => Carbon::now()])->save();

        Notification::make()
            ->title(__('setup.deferred.title'))
            ->body(__('setup.deferred.body'))
            ->success()
            ->send();

        $this->redirect(Dashboard::getUrl());
    }

    /**
     * «Δεν το χρειάζομαι»: retire the guide altogether (2026-09-22).
     *
     * *«από κάπου να ανοίγει συνέχεια ρύθμισης, αλλά να υπάρχει και τελείως
     * skip»* — so this is the second half of that sentence. No gate, no menu
     * item, no badge, no checklist on the home page.
     *
     * It does **not** mark the account as set up: `onboarding_completed_at`
     * means finished, and claiming that about an operator who declined the
     * guide would put a false line in their own record. The page keeps working
     * at its own address and Ρυθμίσεις keeps a card pointing at it, which is
     * the first half of the same sentence.
     */
    public function dismissSetup(): void
    {
        $tenant = $this->guardedTenant();

        $this->persistBusiness();
        $this->persistVat();

        $tenant->forceFill(['onboarding_dismissed_at' => Carbon::now()])->save();

        Notification::make()
            ->title(__('setup.dismissed.title'))
            ->body(__('setup.dismissed.body'))
            ->success()
            ->send();

        $this->redirect(Dashboard::getUrl());
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

    /**
     * The ladders on offer, in the order the platform arranged them.
     *
     * A table since 2026-09-23, not a `const` — these are maintained in
     * `/admin`, and adding a fourth or moving a percentage used to mean editing
     * three files and deploying. {@see PolicyTemplate::ladder()} also builds the
     * printed lines from the same numbers that get written, which the old split
     * between `presetLadder()` and `lang/setup.php` could not promise.
     *
     * @return Collection<int, PolicyTemplate>
     */
    public function policyTemplates(): Collection
    {
        return PolicyTemplate::query()->offered()->get();
    }

    public function choosePreset(string $preset): void
    {
        // Checked against what is actually on offer, not against a fixed list:
        // a retired template must not be selectable by a stale click.
        if ($this->policyTemplates()->contains('code', $preset)) {
            $this->policyPreset = $preset;
        }
    }

    /**
     * The template selected when the operator has not chosen yet — the first
     * one the platform offers.
     *
     * Empty string when the admin has retired every row. The step then has
     * nothing to show and {@see self::persistCancellation()} writes nothing,
     * which is the honest outcome: better a skipped step than a policy the
     * platform no longer stands behind.
     */
    public function defaultPreset(): string
    {
        return (string) ($this->policyTemplates()->first()->code ?? '');
    }

    /** The template currently selected on the cancellation step, if any. */
    public function selectedTemplate(): ?PolicyTemplate
    {
        $templates = $this->policyTemplates();
        $code = $this->policyPreset !== '' ? $this->policyPreset : $this->defaultPreset();

        return $templates->firstWhere('code', $code);
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

        $template = $this->selectedTemplate();

        // Nothing offered, nothing written. The admin has retired every ladder,
        // and inventing one here would put terms on an operator's trips that
        // the platform deliberately stopped standing behind.
        if (! $template instanceof PolicyTemplate) {
            return;
        }

        // **A copy, not a link.** The operator owns what is written here from
        // this moment: editing the template later must never reach backwards
        // into terms already shown to a guest and emailed to them. Both names
        // are taken because a policy is read in whichever language the guest
        // booked in, not the one the operator set up in.
        app(SaveCancellationPolicy::class)(
            new CancellationPolicy,
            [
                'name' => [
                    'el' => $template->getTranslation('name', 'el', true),
                    'en' => $template->getTranslation('name', 'en', true),
                ],
                'free_cancellation_hours' => $template->free_cancellation_hours,
                'is_default' => true,
            ],
            $template->sortedTiers(),
        );
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

    /**
     * The screens the guide sends people to, for the gate to let through.
     *
     * {@see RequireSetupFirst} holds every panel page back while the guide is
     * unanswered — and these three **are** the guide: the boat, the periods and
     * the first trip are answered on the screens that own them. Without this
     * the hand-off buttons bounced straight back here, which is exactly what
     * the product owner hit (2026-09-22: *«το οποίο δεν ανοίγει κιόλας»*).
     *
     * A list rather than "anything under /app/products": what is allowed is
     * what the guide itself offers, and nothing else.
     *
     * @return list<string>
     */
    public static function handOffUrls(): array
    {
        // The home page is the one step whose work lives elsewhere (Mike,
        // 2026-09-23). The account questions are asked here, as they have
        // been since the catalogue left the guide on 2026-09-22; a home page is
        // blocks, their order and their photographs, and {@see HomePage} owns
        // all of that already.
        return [HomePage::getUrl()];
    }

    /** Where a step whose work lives on another screen sends the operator. */
    public function handOffUrl(string $step): ?string
    {
        return $step === SetupChecklist::HOME_PAGE ? HomePage::getUrl() : null;
    }

    /**
     * Does this operator get a marketing home page from us?
     *
     * Only the closing screen asks (product owner, 2026-09-22: *«άλλαξέ το όταν
     * ο merchant είναι booking pages only, αν χρειάζεται»*). It does need it:
     * the questions apply either way — a bookings-only operator's pages
     * carry the same VAT rate and the same cancellation terms —
     * but «η ιστοσελίδα σας είναι ήδη ζωντανή» describes a home page they were
     * never given.
     */
    public function servesHomePage(): bool
    {
        return $this->guardedTenant()->hosted_site_mode->servesHomePage();
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
