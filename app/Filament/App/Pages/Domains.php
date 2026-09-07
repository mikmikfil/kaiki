<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Tenancy\Actions\CheckDomains;
use App\Domain\Tenancy\Actions\VerifyDomain;
use App\Enums\DomainStatus;
use App\Models\BrandProfile;
use App\Models\TenantDomain;
use App\Support\Tenancy;
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
 * The operator's own domain (HOS-3, ADR-0010 Option A).
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

    protected static ?int $navigationSort = 91;

    protected static string $view = 'filament.app.pages.domains';

    /** @var array<string, mixed> */
    public array $data = [];

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

    /**
     * The tenant's domains, newest last so the list reads as a history.
     *
     * @return Collection<int, TenantDomain>
     */
    public function domains(): Collection
    {
        return TenantDomain::query()->orderBy('id')->get();
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
