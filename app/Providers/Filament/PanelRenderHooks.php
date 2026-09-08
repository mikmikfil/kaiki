<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Hosted\Support\HostedUrl;
use App\Models\Tenant;
use App\Support\Locale\LocaleOptions;
use App\Support\Tenancy;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;

/**
 * Chrome shared by `/app` and `/admin`.
 *
 * **Registered exactly once**, from `AppServiceProvider`. Filament render hooks
 * are global unless a scope is given, so calling this from both panel providers
 * would register each hook twice and render two switchers side by side — which
 * looks like a styling bug and is actually a duplicate registration.
 */
final class PanelRenderHooks
{
    public static function register(): void
    {
        // The topbar, for every authenticated panel page.
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_END,
            static fn (): View => self::localeSwitcher(),
        );

        // A way out to the guest's side of the product, from the top of the
        // sidebar where an operator's eye already is.
        //
        // Registered globally and decided **inside** the hook rather than by a
        // scope. Filament's render-hook scopes name a page or resource class,
        // not a panel, so scoping this to `/app` would mean listing every page
        // in it — and the condition that actually matters is a resolved tenant,
        // which the super-admin at `/admin` never has (ADR-0020).
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_NAV_START,
            static fn (): View => self::viewFrontend(),
        );

        // The navigation drawer, on a phone's first visit. Global rather than
        // scoped for the same reason as the link above, and harmless at `/admin`
        // — a super-admin on a phone wants the page too.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (): View => view('filament.sidebar-first-visit'),
        );

        // Row actions a thumb can hit, below the desktop breakpoint.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (): View => view('filament.touch-targets'),
        );

        // Login and password reset render a "simple page" with no topbar. This
        // is the one place the switcher matters most: an operator who cannot
        // read the sign-in form has no other way to change the language, and no
        // account yet to store a preference on.
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIMPLE_PAGE_START,
            static fn (): View => self::localeSwitcher(alignEnd: true),
        );
    }

    /**
     * The operator's own landing page, opened in a new tab.
     *
     * ## Why it can be absent
     *
     * HOS-6 lets an operator switch their hosted pages off, and the page is then
     * a 404 — so the link is only rendered when {@see HostedUrl::enabledFor()}
     * says there is something at the other end. A button that leads to a "not
     * found" is worse than no button, because the operator concludes the feature
     * is broken rather than switched off.
     *
     * A new tab rather than a navigation: the operator is in the middle of
     * editing something, and sending them away from a half-finished form to
     * look at the result of the last one is how work gets lost.
     */
    private static function viewFrontend(): View
    {
        $tenant = Tenancy::current();

        return view('filament.view-frontend', [
            'url' => $tenant instanceof Tenant && HostedUrl::enabledFor($tenant)
                ? HostedUrl::operator($tenant)
                : null,
        ]);
    }

    private static function localeSwitcher(bool $alignEnd = false): View
    {
        return view('filament.locale-switcher', [
            'locales' => LocaleOptions::forRequest(request()),
            'alignEnd' => $alignEnd,
        ]);
    }
}
