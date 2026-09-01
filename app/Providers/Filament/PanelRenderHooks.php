<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Support\Locale\LocaleOptions;
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

        // Login and password reset render a "simple page" with no topbar. This
        // is the one place the switcher matters most: an operator who cannot
        // read the sign-in form has no other way to change the language, and no
        // account yet to store a preference on.
        FilamentView::registerRenderHook(
            PanelsRenderHook::SIMPLE_PAGE_START,
            static fn (): View => self::localeSwitcher(alignEnd: true),
        );
    }

    private static function localeSwitcher(bool $alignEnd = false): View
    {
        return view('filament.locale-switcher', [
            'locales' => LocaleOptions::forRequest(request()),
            'alignEnd' => $alignEnd,
        ]);
    }
}
