<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Controllers\ExportDownloadController;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureTenantIsWritable;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use App\Policies\TenantOwnedPolicy;
use App\Support\Tenancy;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The operator back-office at `/app` (spec SCP-1, ARC-4).
 *
 * Tenant context comes from the signed-in user via `ResolveTenant`, which is
 * strategy (4) of TEN-4. There is **no tenant switcher**: ADR-0020 Option C
 * gives each user exactly one tenant, and a super-admin reaches an operator
 * through impersonation (TEN-7, M7) rather than a dropdown. A dropdown would
 * make "which tenant am I acting as" a piece of session state, which is exactly
 * where a cross-tenant mistake becomes possible.
 *
 * `EnsureTenantIsWritable` sits in the panel middleware, but **it is not what
 * blocks a panel write** — see #43. Livewire hands it a synthesized request
 * carrying the original page-load method, so its safe-method check short
 * circuits on every Filament action. TEN-9 is enforced in
 * {@see TenantOwnedPolicy}, which every resource inherits. The
 * middleware stays for the non-Livewire POSTs, where the method is honest.
 * Reads keep working either way, because the operator still needs to see their
 * own bookings while sorting out a payment.
 */
class AppPanelProvider extends PanelProvider
{
    /**
     * CNV-2: stored in UTC, **displayed in the tenant timezone**.
     *
     * Registered once here rather than field by field, because the alternative
     * is every resource in M1 remembering a `->timezone()` call and one of them
     * not. `config('app.timezone')` is UTC and tenants default to
     * `Europe/Athens`, so the default is silently wrong by two or three hours —
     * survivable on an API key's expiry, not survivable on a departure time,
     * where CNV-3 requires `local_time` and `starts_at_utc` to agree.
     *
     * This is global to both panels by nature. That is correct: `/admin` has no
     * tenant to resolve, so a super-admin falls back to UTC, which is the right
     * frame for someone looking across every operator at once.
     */
    public function boot(): void
    {
        $timezone = static function (): string {
            $tenant = Tenancy::current();

            return $tenant === null
                ? (string) config('app.timezone')
                : $tenant->timezone;
        };

        DateTimePicker::configureUsing(
            static fn (DateTimePicker $component): DateTimePicker => $component->timezone($timezone),
        );

        DatePicker::configureUsing(
            static fn (DatePicker $component): DatePicker => $component->timezone($timezone),
        );

        TextColumn::configureUsing(
            static fn (TextColumn $column): TextColumn => $column->timezone($timezone),
        );
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->brandName(config('app.name'))
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('panel.groups.operations')),
                NavigationGroup::make()->label(fn (): string => __('panel.groups.catalogue')),
                NavigationGroup::make()->label(fn (): string => __('panel.groups.settings')),
            ])
            /*
             * The export download (OPS-18).
             *
             * `authenticatedRoutes` rather than `routes` or an entry in
             * `routes/web.php`, so the link inherits this panel's whole stack —
             * the session guard, `ResolveTenant`, and therefore the tenant
             * scope that makes another operator's uuid resolve to nothing.
             *
             * A signed temporary URL was the alternative and is a bearer
             * credential over a spreadsheet of guest names, emails and phone
             * numbers; see {@see ExportDownloadController}.
             */
            ->authenticatedRoutes(fn (): mixed => Route::get(
                'exports/{uuid}/download',
                ExportDownloadController::class,
            )->name('exports.download'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                AddSecurityHeaders::class,
                // Listed here rather than in `authMiddleware` so the login page
                // is localised too — an operator who cannot read the sign-in
                // form is the last person able to fix their own locale. It
                // still runs *after* `ResolveTenant`, which lives in the auth
                // stack: the middleware priority list in `bootstrap/app.php`
                // guarantees the order, because listing it twice would be
                // silently deduplicated. See `SetLocale`.
                SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // After authentication, so the session user exists to resolve from.
                ResolveTenant::class,
                EnsureTenantIsWritable::class,
                // isPersistent, or none of this runs on `POST /livewire/update`
                // — which is every button in the panel. Filament only forwards
                // auth middleware to Livewire's persistent list when asked, and
                // without it `Tenancy::current()` is null on each action: the
                // tenant scope throws and the operator gets a 500. A component
                // test cannot catch this, because it never reaches that route.
            ], isPersistent: true);
    }
}
