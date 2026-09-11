<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Tenancy\Actions\CheckDomains;
use App\Domain\Tenancy\Actions\VerifyDomain;
use App\Domain\Tenancy\Support\PlanLimits;
use App\Enums\DomainStatus;
use App\Enums\HostedSiteMode;
use App\Models\BrandProfile;
use App\Models\TenantDomain;
use App\Support\Tenancy;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The operator's public site: which pages, and at what address (HOS-3, ADR-0029,
 * ADR-0010 Option A).
 *
 * ## Two settings, and the first decides whether the second matters
 *
 * ADR-0029 turned `hosted_page_enabled` into three states, and this is where an
 * operator picks one — **there was no control at all before, not even for the
 * boolean**. An operator could not switch their own site on or off through the
 * product; it was a column somebody set in a seeder.
 *
 * It sits above the domain because it is the larger question: there is no point
 * pointing `book.example.gr` at pages nobody is serving. Both are the same
 * decision from the operator's side — what the public sees of them — which is
 * why the screen is «Η ιστοσελίδα σας» rather than «Το domain σας».
 *
 * ## What the screen has to do is mostly explain
 *
 * The work is a DNS record the operator creates at a registrar we have no
 * access to, so the screen's real job is to show **the exact CNAME**, say where
 * it goes, and then tell the truth about what is happening while it propagates.
 * A domain that says `pending` for an hour with no explanation is a support
 * message; one that says "we are checking every fifteen minutes and it can take
 * up to a day" is not.
 *
 * ## Verification is a button *and* a sweep
 *
 * The button is for the operator who is still sitting there. The scheduled
 * sweep is what makes it work for the one who set it up and went to bed —
 * {@see CheckDomains}.
 *
 * ## Gated like branding, because it is the same kind of decision
 *
 * A custom domain is what the business calls itself in public. Crew reach
 * neither this nor the logo (TEN-8), and the page is gated on the brand profile
 * for the reason the search-settings screen gives: a page has no model of its
 * own to hang a policy on, and Filament allows what it cannot check.
 */
class Domains extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 91;

    protected static string $view = 'filament.app.pages.domains';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $modeData = [];

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('domains.nav');
    }

    public function getTitle(): string
    {
        return __('domains.title');
    }

    public function getSubheading(): ?string
    {
        return __('domains.subtitle');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', BrandProfile::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->getForm('form')?->fill();
        $this->getForm('modeForm')?->fill(['hosted_site_mode' => Tenancy::current()?->hosted_site_mode?->value]);
    }

    /** @return array<string, Form> */
    protected function getForms(): array
    {
        // Two forms rather than one, because they are saved by different
        // buttons and a shared `statePath` would let adding a domain silently
        // rewrite the site mode with whatever happened to be on screen.
        return [
            'form' => $this->form(Form::make($this)),
            'modeForm' => $this->modeForm(Form::make($this)),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('hostname')
                    ->label(__('domains.form.hostname.label'))
                    ->helperText(__('domains.form.hostname.help'))
                    ->placeholder('book.example.gr')
                    ->maxLength(190),
            ])
            ->statePath('data');
    }

    public function modeForm(Form $form): Form
    {
        return $form
            ->schema([
                Radio::make('hosted_site_mode')
                    ->label(__('domains.mode.label'))
                    ->options(HostedSiteMode::options())
                    // The sentence under each choice, from the enum's own lang
                    // block. Three labels alone — «Καμία», «Μόνο κρατήσεις»,
                    // «Πλήρης» — do not tell an operator which one they are,
                    // and this is a decision they make once and live with.
                    ->descriptions(array_map(
                        static fn (HostedSiteMode $mode): string => (string) __(
                            HostedSiteMode::translationNamespace() . '.' . $mode->value . '.help'
                        ),
                        array_combine(
                            array_map(static fn (HostedSiteMode $mode): string => $mode->value, HostedSiteMode::cases()),
                            HostedSiteMode::cases(),
                        ),
                    ))
                    ->required(),
            ])
            ->statePath('modeData');
    }

    public function saveMode(): void
    {
        abort_unless(static::canAccess(), 403);

        // TEN-9 through the same gate the rest of this screen uses: a lapsed
        // subscription opens the panel and writes nothing.
        abort_unless(Gate::allows('update', BrandProfile::query()->firstOrFail()), 403);

        $tenant = Tenancy::current();

        abort_unless($tenant !== null, 403);

        $state = (array) $this->getForm('modeForm')?->getState();

        $tenant->forceFill([
            'hosted_site_mode' => HostedSiteMode::from((string) $state['hosted_site_mode']),
        ])->save();

        Notification::make()->title(__('domains.mode.saved'))->success()->send();
    }

    /**
     * The tenant's domains, newest last so the list reads as a history.
     *
     * @return Collection<int, TenantDomain>
     */
    public function domains(): Collection
    {
        return TenantDomain::query()->orderBy('id')->get();
    }

    /**
     * Whether this operator's plan includes their own domain (SAA-3, SAA-8).
     *
     * Asked before a domain is *added* and nowhere else. A verified domain on
     * an operator who has since moved down a plan keeps serving: taking a
     * business's website off the air is not what a billing change should do.
     */
    public function allowsCustomDomain(): bool
    {
        $tenant = Tenancy::current();

        return $tenant !== null && PlanLimits::canUseCustomDomain($tenant);
    }

    public function upgradeUrl(): string
    {
        return PlanLimits::upgradeUrl();
    }

    /** What the operator types into their registrar. */
    public function target(): string
    {
        return (string) config('kaiki.tenancy.custom_domain_target');
    }

    public function add(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(Gate::allows('update', BrandProfile::query()->firstOrFail()), 403);

        // The screen shows no form on a plan without domains; this is the same
        // rule for a request that did not come from the screen.
        if (! $this->allowsCustomDomain()) {
            Notification::make()
                ->title(__('plans.pro_only'))
                ->body(__('plans.domains.body'))
                ->warning()
                ->send();

            return;
        }

        $state = (array) $this->getForm('form')?->getState();
        $hostname = TenantDomain::normalise((string) ($state['hostname'] ?? ''));

        try {
            $this->guard($hostname);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        TenantDomain::query()->create([
            'hostname' => $hostname,
            'status' => DomainStatus::Pending,
            // The token is minted now even though ADR-0010 verifies by CNAME
            // rather than by a TXT record: it costs nothing, and a registrar
            // that refuses a CNAME on an apex is the case where a second method
            // is needed. Storing it from the start means that day is a feature
            // rather than a migration.
            'verification_token' => bin2hex(random_bytes(16)),
        ]);

        $this->getForm('form')?->fill();

        Notification::make()->title(__('domains.added'))->success()->send();
    }

    public function verify(int $domainId): void
    {
        abort_unless(static::canAccess(), 403);

        $domain = TenantDomain::query()->findOrFail($domainId);
        $verified = app(VerifyDomain::class)($domain);

        Notification::make()
            ->title(__($verified ? 'domains.verified' : 'domains.not_yet'))
            ->body($verified ? null : __('domains.not_yet_body'))
            ->status($verified ? 'success' : 'warning')
            ->send();
    }

    public function remove(int $domainId): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(Gate::allows('update', BrandProfile::query()->firstOrFail()), 403);

        TenantDomain::query()->findOrFail($domainId)->delete();

        Notification::make()->title(__('domains.removed'))->success()->send();
    }

    /**
     * SEC-4: a hostname, and not one somebody else already proved they own.
     *
     * The cross-tenant check reads **without tenancy** on purpose. Scoped, it
     * would find nothing and cheerfully let two operators claim the same
     * hostname — and the second one to verify would start serving the first
     * one's guests.
     *
     * @throws ValidationException
     */
    protected function guard(string $hostname): void
    {
        if ($hostname === '' || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $hostname) !== 1) {
            throw ValidationException::withMessages(['hostname' => __('domains.errors.invalid')]);
        }

        foreach ([config('kaiki.tenancy.hosted_host'), config('app.url')] as $reserved) {
            if ($hostname === TenantDomain::normalise((string) $reserved)) {
                throw ValidationException::withMessages(['hostname' => __('domains.errors.reserved')]);
            }
        }

        $taken = Tenancy::withoutTenancy(static fn (): ?TenantDomain => TenantDomain::query()
            ->where('hostname', $hostname)
            ->first());

        if ($taken !== null) {
            // The same message whether it is theirs or somebody else's: telling
            // an operator "another account has verified this" would confirm the
            // existence of that account to anybody who guessed a hostname.
            throw ValidationException::withMessages(['hostname' => __('domains.errors.taken')]);
        }
    }
}
