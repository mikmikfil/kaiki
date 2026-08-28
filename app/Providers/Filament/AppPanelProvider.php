<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureTenantIsWritable;
use App\Http\Middleware\ResolveTenant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
 * `EnsureTenantIsWritable` sits in the panel middleware so a lapsed
 * subscription blocks writes here without any resource having to remember
 * (TEN-9). Reads keep working, because the operator still needs to see their
 * own bookings while sorting out a payment.
 */
class AppPanelProvider extends PanelProvider
{
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
            ])
            ->authMiddleware([
                Authenticate::class,
                // After authentication, so the session user exists to resolve from.
                ResolveTenant::class,
                EnsureTenantIsWritable::class,
            ]);
    }
}
