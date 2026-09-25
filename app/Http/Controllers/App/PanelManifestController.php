<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Support\PanelApp;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest that makes `/app` installable (PWA, 2026-09-23).
 *
 * A route rather than a file in `public/`, so the description and the
 * shortcuts are in the operator's language, and the URLs follow the panel's
 * own routes. Linked with `crossorigin="use-credentials"`, because a manifest
 * is otherwise fetched without cookies and would always come back in the
 * default language.
 *
 * Outside the sign-in wall: Chrome reads it on the sign-in page too, so the
 * app can be installed before anybody has signed in. Nothing in it is about
 * an operator.
 */
class PanelManifestController
{
    public function __invoke(): JsonResponse
    {
        $scope = PanelApp::scope();
        $name = (string) config('app.name');

        $icons = [];

        foreach ([192, 512] as $size) {
            $icons[] = ['src' => PanelApp::icon("icon-{$size}.png"), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
        }

        foreach ([192, 512] as $size) {
            $icons[] = ['src' => PanelApp::icon("maskable-{$size}.png"), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'maskable'];
        }

        $shortcut = static fn (string $label, string $url, string $icon): array => [
            'name' => $label,
            'short_name' => $label,
            'url' => $url,
            'icons' => [['src' => PanelApp::icon($icon), 'sizes' => '96x96', 'type' => 'image/png']],
        ];

        $manifest = [
            // The app's identity, which stays put if `start_url` ever changes.
            'id' => $scope,
            'name' => $name,
            'short_name' => $name,
            'description' => __('pwa.description'),
            'lang' => app()->getLocale(),
            'dir' => 'ltr',
            // `?source=pwa` tells a launch from the home screen apart in the
            // access log; the panel itself ignores it.
            'start_url' => $scope . '?source=pwa',
            'scope' => $scope,
            'display' => 'standalone',
            // The splash screen: the icon on its own navy.
            'background_color' => PanelApp::NAVY,
            'theme_color' => PanelApp::NAVY,
            'icons' => $icons,
            // A long press on the icon.
            'shortcuts' => [
                $shortcut(__('pwa.shortcuts.boarding'), PanelApp::path(route('filament.app.boarding', ['camera' => 1])), 'shortcut-boarding.png'),
                $shortcut(__('pwa.shortcuts.calendar'), PanelApp::path(route('filament.app.pages.calendar')), 'shortcut-calendar.png'),
            ],
        ];

        return response()->json($manifest, 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'no-cache',
            // In the session's language; see the class comment.
            'Vary' => 'Cookie',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
