<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Avatars\InitialsAvatarProvider;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
 * The platform super-admin panel at `/admin` (spec SCP-13).
 *
 * Deliberately **not** tenant-scoped: a super-admin has no `tenant_id`, and
 * their job is to see across operators. `ResolveTenant` is therefore absent
 * here, which is the one place in the application where that is correct.
 *
 * A different colour from `/app` on purpose. Someone who can see every
 * operator's data should be able to tell at a glance which panel they are
 * looking at — a mistake made here is not recoverable by the person who makes
 * it.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
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
            ->path('admin')
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
                'primary' => Color::Rose,
            ])
            ->brandName(fn (): string => __('panel.admin.brand'))
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
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
                // is localised too. There is no tenant to inherit from in this
                // panel, so the chain here is `?lang=`, then the super-admin's
                // own `users.locale`, then `Accept-Language`, then `en`.
                SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
