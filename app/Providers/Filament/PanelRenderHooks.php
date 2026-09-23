<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Hosted\Support\HostedUrl;
use App\Domain\Platform\Support\Announcements;
use App\Filament\App\Navigation\BoxMenu;
use App\Filament\App\Navigation\SiblingScreens;
use App\Filament\App\Pages\Analytics;
use App\Filament\App\Pages\Settings;
use App\Filament\App\Pages\Setup;
use App\Filament\Support\DarkPrimary;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformBrand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Locale\LocaleOptions;
use App\Support\PanelApp;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

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
        self::platformColors();

        /*
         * **«Βλέπετε ως …»** — TEN-7's persistent banner (2026-09-23).
         *
         * `BODY_START`, so it sits above the whole panel rather than inside a
         * page: a super-admin signed in as somebody else must never reach a
         * screen where the banner has scrolled out of view, because every row
         * written from here is recorded as that person's own work.
         *
         * Registered globally and decided inside the view, like the frontend
         * link below and for the same reason: the condition is a fact about the
         * session rather than about a page.
         */
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_START,
            static fn (): View => view('filament.impersonation-banner'),
        );

        /*
         * **The panel as an app on a phone** (PWA, 2026-09-23): the manifest,
         * the status-bar colour, the iPhone's icon and the service worker, on
         * every `/app` page including the sign-in screens — Chrome decides
         * whether a page is installable from its `<head>`, and somebody who has
         * not signed in yet should be able to install it too. `/admin` is not
         * an app. See {@see PanelApp}.
         */
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (array $scopes): View|string => Filament::getCurrentPanel()?->getId() === 'app'
                ? self::pwaHead($scopes)
                : '',
        );

        // «Εγκατάσταση εφαρμογής» under the profile in the user menu, and the
        // two steps an iPhone needs instead. Hidden by the page's own script
        // unless there is an install to offer; see the views.
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_PROFILE_AFTER,
            static fn (): View|string => Filament::getCurrentPanel()?->getId() === 'app'
                ? view('filament.app.install-menu-item')
                : '',
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            static fn (): View|string => Filament::getCurrentPanel()?->getId() === 'app' && Auth::check()
                ? view('filament.app.install-ios')
                : '',
        );

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

        // − and + either side of every number field, on both panels
        // (product owner, 2026-09-22: *«όπου στο διαχειριστικό έχει βελάκια
        // πάνω κάτω για αύξηση αριθμού, τα θέλω οριζόντια + και −»*). The
        // buttons themselves are hung on the fields by
        // {@see \App\Filament\Support\NumberSteppers}; this is the stylesheet
        // that takes the browser's own spinner away and the handler behind the
        // press.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (): View => view('filament.number-steppers'),
        );

        // The water, on **both** panels (product owner, 2026-09-22: *«και τα
        // κύματα βάλτα και στο admin περιβάλλον»*). `/admin` used to keep
        // Filament's plain look on the argument that it is how somebody with
        // two tabs open tells them apart — the blue sidebar still does that, so
        // the waves were the half of it that was only ever decoration.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (): View => view('filament.waves'),
        );

        // The operator panel's blue sidebar. Decided inside the hook, like the
        // rest: `/admin` keeps Filament's plain chrome above the water.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (): View|string => Filament::getCurrentPanel()?->getId() === 'app'
                ? view('filament.app.sea')
                : '',
        );

        // A phone's way round `/app` (mobile direction A, 2026-09-17): «Μενού»
        // in the top bar, and Menu 1 as boxes over the page. Both are hidden
        // from `lg` up, where the blue sidebar is the menu. `/admin` keeps
        // Filament's drawer, like the rest of its plain look.
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_START,
            static fn (): View|string => Filament::getCurrentPanel()?->getId() === 'app' && Tenancy::check()
                ? view('filament.app.mobile-menu-button')
                : '',
        );

        // At the start of the body rather than the end: it is a fixed layer,
        // so where it sits in the markup changes nothing on screen, and ahead
        // of the sidebar the sidebar stays the last place the menu's labels
        // appear in the page, which `SettingsHubTest` reads the footer by.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_START,
            static fn (): View|string => Filament::getCurrentPanel()?->getId() === 'app' && Tenancy::check() && Auth::check()
                ? view('filament.app.mobile-menu', ['groups' => BoxMenu::groups(), 'tenant' => (string) Tenancy::current()?->name])
                : '',
        );

        // Lists as boxes on a phone: every table in `/app` below `md`.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (): View|string => Filament::getCurrentPanel()?->getId() === 'app'
                ? view('filament.app.box-lists')
                : '',
        );

        // Tabs between screens that share one sidebar entry (Menu 1). Scoped to
        // their list pages, so create and edit forms stay uncluttered.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            static fn (array $scopes): View => view('filament.app.sibling-tabs', ['tabs' => SiblingScreens::tabsFor($scopes)]),
            scopes: SiblingScreens::listPages(),
        );

        // «Ρυθμίσεις» at the very bottom of the sidebar, outside the scrolling
        // menu. Decided inside the hook, like the link at the top: `/admin` has
        // no tenant and gets nothing, and crew — who may open none of the
        // cards — get nothing either.
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIDEBAR_FOOTER,
            static fn (): View|string => self::settingsItem(),
        );

        // The platform's announcement (SAA-1), above every page's content in
        // `/app`. Decided inside the hook, like the rest: a resolved tenant is
        // what makes this the operator panel, and `/admin` never has one.
        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
            static fn (): View|string => self::announcement(),
        );

        // The way back to «Ρυθμίσεις», on every screen its cards lead to.
        // Scoped by class, and a resource's own class is in the scope of all
        // its pages (`Resources\Pages\Page::getRenderHookScopes`), so list,
        // create and edit get it alike. `getUrl()` is resolved at render time,
        // inside the panel, not here at boot.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            static fn (): View => view('filament.app.settings-hub-back', ['url' => Settings::getUrl()]),
            scopes: Settings::destinations(),
        );

        /*
         * The Kaiki mark, above the heading of the setup guide (product owner,
         * 2026-09-22: *«βάλε μου πάνω αριστερά το logo kaiki»*).
         *
         * That screen drops the navigation on purpose — it is the first thing
         * a new operator sees and the panel is not there yet — which leaves
         * nothing on it saying whose product this is. `PAGE_START` is where the
         * settings back-link goes, above the title, which is exactly «πάνω
         * αριστερά».
         *
         * The panel's own brand: an uploaded platform logo when there is one,
         * the name when there is not.
         */
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            static fn (): View => view('filament.app.setup-brand'),
            scopes: [Setup::class],
        );

        // The split sign-in screen: a photograph on one half, the form on the
        // other.
        //
        // `SIMPLE_PAGE_START` rather than `HEAD_END`, which is where it started.
        // Every rule in it is scoped to `.fi-simple-layout` — a class only the
        // login and password-reset pages carry — so on every other page it was
        // inert, and "inert" was the whole argument for leaving it in the head
        // of all of them.
        //
        // It is not inert, though: the stylesheet *names* `.kaiki-locale-switcher`
        // in a selector, and `LocaleSwitcherTest` asserts that a tenant selling
        // in one language is served a page with that string nowhere in it. A
        // style rule is not a switcher, but the test is right that the string
        // should not be there — nothing on that page has anything to do with
        // one. A `<style>` in the body is valid and applies, and this way the
        // rules ship only with the screens they style.
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIMPLE_PAGE_START,
            static fn (): View => view('filament.auth-split'),
        );

        // The brand mark above the sign-in form on a phone. **Registered before
        // the switcher below**, because hooks render in the order they are
        // added and the order asked for is mark, then language, then form —
        // which the markup cannot produce on its own. See the view.
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIMPLE_PAGE_START,
            static fn (): View => view('filament.auth-brandmark'),
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
     * The platform's palette, from `/admin` → Εμφάνιση.
     *
     * `Filament::serving()` rather than each panel's `->colors()`, and the
     * difference is when the database is read. `->colors()` takes an array,
     * so the value has to exist while the provider is registering — which is
     * also what happens during `artisan migrate` on a database with no tables,
     * during `config:cache`, and in a container whose database is not up. This
     * runs only when a panel is actually being served to somebody.
     *
     * `Color::hex()` turns one colour into the eleven shades Filament needs;
     * the two here are the only ones the screen lets anybody change, so nothing
     * else in the palette can be left in an unreadable state by a bad pair.
     *
     * Dark mode gets its own primary scale, derived from the same colour.
     */
    private static function platformColors(): void
    {
        Filament::serving(static function (): void {
            FilamentColor::register([
                'primary' => Color::hex(PlatformBrand::primary()),
                'accent' => Color::hex(PlatformBrand::accent()),
            ]);
        });

        // The same primary, lighter, for dark mode: navy on near-black was
        // unreadable (2026-09-23). Read at render, like the palette above.
        // See {@see DarkPrimary}.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            static fn (): Htmlable => DarkPrimary::style(PlatformBrand::primary()),
        );
    }

    /**
     * The operator's own landing page, opened in a new tab.
     *
     * ## Why it can be absent
     *
     * HOS-6 lets an operator switch their hosted pages off, and the page is then
     * a 404 — so the link is only rendered when {@see HostedUrl::homeEnabledFor()}
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
            'url' => $tenant instanceof Tenant && HostedUrl::homeEnabledFor($tenant)
                ? HostedUrl::operator($tenant)
                : null,
        ]);
    }

    /**
     * The sidebar's foot: «Στατιστικά» then «Ρυθμίσεις», each only for somebody
     * who may open it, and nothing at all when neither applies.
     */
    private static function settingsItem(): View|string
    {
        if (! Tenancy::check()) {
            return '';
        }

        $settings = Settings::canAccess();

        if (! $settings && ! Analytics::canAccess()) {
            return '';
        }

        return view('filament.app.settings-sidebar', [
            // «Στατιστικά» above «Ρυθμίσεις», at the foot of the sidebar (Menu 1).
            'analytics' => Analytics::canAccess() ? [
                'url' => Analytics::getUrl(),
                'label' => Analytics::getNavigationLabel(),
                'icon' => Analytics::getNavigationIcon(),
                'active' => request()->routeIs(Analytics::getRouteName()),
            ] : null,
            'settings' => $settings,
            'url' => Settings::getUrl(),
            'label' => Settings::getNavigationLabel(),
            'icon' => Settings::getNavigationIcon(),
            'active' => Settings::isCurrent(),
            'badge' => Settings::getNavigationBadge(),
        ]);
    }

    /**
     * The current announcement for this person, or nothing.
     *
     * Nothing at `/admin` (no tenant) and nothing for a super-admin: the person
     * who wrote the notice does not need it on every page of a panel they
     * reach only by impersonation, and impersonation does not exist yet.
     */
    private static function announcement(): View|string
    {
        if (! Tenancy::check()) {
            return '';
        }

        $user = Auth::user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return '';
        }

        $announcement = Announcements::currentFor($user);

        if (! $announcement instanceof PlatformAnnouncement) {
            return '';
        }

        return view('filament.app.announcement-banner', [
            'message' => $announcement->message,
            'severity' => $announcement->severity->value,
            'dismissUrl' => route('filament.app.announcements.dismiss', ['announcement' => $announcement->getKey()]),
        ]);
    }

    /**
     * @param  array<int, string>  $scopes
     */
    private static function pwaHead(array $scopes): View
    {
        return view('filament.app.pwa-head', [
            'manifestUrl' => PanelApp::path(route('filament.app.manifest')),
            'workerUrl' => PanelApp::path(route('filament.app.sw')),
            'scope' => PanelApp::scope(),
            'appName' => (string) config('app.name'),
            'appleIcon' => PanelApp::icon('apple-touch-icon.png'),
            'themeColor' => PanelApp::themeColor($scopes),
            'topbar' => PanelApp::TOPBAR,
            'topbarDark' => PanelApp::TOPBAR_DARK,
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
