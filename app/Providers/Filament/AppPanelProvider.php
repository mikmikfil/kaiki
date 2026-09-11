<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\App\Pages\Settings;
use App\Filament\Avatars\InitialsAvatarProvider;
use App\Http\Controllers\App\BoardingController;
use App\Http\Controllers\App\BoardingServiceWorkerController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureTenantIsWritable;
use App\Http\Middleware\OfferSetupOnce;
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
            /*
             * Initials drawn locally, rather than Filament's default.
             *
             * The default is `UiAvatarsProvider`, which puts the signed-in
             * person's name in a `https://ui-avatars.com/api/?name=…` URL — so
             * every page of this panel was sending a staff member's name to a
             * third party nobody had chosen, on every load. See
             * {@see InitialsAvatarProvider}.
             */
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->path('app')
            ->login()
            /*
             * Password reset, which the panel did not have.
             *
             * Two things needed it. A colleague invited through
             * {@see \App\Domain\Tenancy\Actions\InviteStaffMember} sets their
             * own password through this flow, so without the routes registered
             * the invitation link 404s — worse than no invitation, because the
             * owner believes they sent one. And an operator who forgot their
             * password had no way back in at all: there was no reset route on
             * either panel, so the only recovery was somebody editing the
             * database.
             */
            ->passwordReset()
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
            /*
             * Two groups, and «Ρυθμίσεις» is a page rather than a third.
             *
             * What an operator does every morning is at the top: boarding, the
             * calendar, bookings, quotes, enquiries, vouchers. Underneath is the
             * catalogue they touch once a season.
             *
             * Everything else is something you go looking for: the
             * notification log, the exports your accountant asked for, what went
             * wrong, the calendar sync, the keys and the domain. Until
             * 2026-09-11 that was a third, collapsed group; now it is one item
             * leading to a page of cards ({@see Settings}), each with an icon
             * and a line saying what it is for (product owner). Fifteen screens
             * you rarely need read better as a page you can scan than as a list
             * you first have to open.
             */
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('panel.groups.operations')),
                NavigationGroup::make()->label(fn (): string => __('panel.groups.catalogue')),
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
            ->authenticatedRoutes(function (): void {
                Route::get('exports/{uuid}/download', ExportDownloadController::class)
                    ->name('exports.download');

                /*
                 * The offline boarding page (OPS-12).
                 *
                 * Inside `authenticatedRoutes` for the same reason the export
                 * download is: it inherits the panel's session guard and
                 * `ResolveTenant`, so a crew member's phone is scoped to their
                 * own operator without this controller having to arrange it.
                 *
                 * The service worker is served from **inside** the boarding
                 * path, because a worker's scope is the directory it comes
                 * from. At `/app/boarding/sw.js` it can only ever control
                 * `/app/boarding/…`; served from the root it would cache
                 * authenticated panel HTML, and a phone handed on after
                 * somebody signed out would still render the last operator's
                 * screens.
                 */
                Route::get('boarding', [BoardingController::class, 'show'])
                    ->name('boarding');

                Route::get('boarding/sw.js', BoardingServiceWorkerController::class)
                    ->name('boarding.sw');

                Route::post('boarding/scan', [BoardingController::class, 'scan'])
                    ->name('boarding.scan');
            })
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
                // After the tenant is resolved, because what it decides is a
                // question about the tenant. Last in the stack, so it never
                // stands between a request and the guard that would refuse it
                // (#51, SAA-10).
                OfferSetupOnce::class,
                // isPersistent, or none of this runs on `POST /livewire/update`
                // — which is every button in the panel. Filament only forwards
                // auth middleware to Livewire's persistent list when asked, and
                // without it `Tenancy::current()` is null on each action: the
                // tenant scope throws and the operator gets a 500. A component
                // test cannot catch this, because it never reaches that route.
            ], isPersistent: true);
    }
}
