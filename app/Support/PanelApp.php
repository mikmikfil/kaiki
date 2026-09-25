<?php

declare(strict_types=1);

namespace App\Support;

use Composer\InstalledVersions;
use Filament\Pages\SimplePage;

/**
 * The operator panel as an app on a phone's home screen (PWA, 2026-09-23).
 *
 * One place for the facts the manifest, the service worker and the page's
 * `<head>` must agree on: where the app starts and ends, its colours, its
 * icons, and the version that decides when a phone drops its cached files.
 *
 * ## The scope is `/app`, not `/app/`
 *
 * The panel's home page is `/app` itself, which `/app/` does not contain — so a
 * manifest scoped to `/app/` would put its own start page outside the app, and
 * a worker scoped to `/app/` would never control the dashboard. Scopes are
 * prefixes, so `/app` covers `/app`, `/app?…` and `/app/…`, and `/admin` is not
 * among them. `/app/boarding` has a worker of its own with the longer scope,
 * and the longer scope wins.
 */
final class PanelApp
{
    /** The navy of the sign-in band and the sidebar, which the icons are drawn on. */
    public const NAVY = '#0F2E57';

    /** Filament's top bar in light mode: the colour of the phone's status bar above it. */
    public const TOPBAR = '#FFFFFF';

    /** Filament's top bar in dark mode (`gray-900`, which is zinc). */
    public const TOPBAR_DARK = '#18181B';

    public const ICON_DIR = 'images/app-icon';

    /** The panel's root path, `/app`. */
    public static function scope(): string
    {
        return rtrim((string) parse_url(route('filament.app.pages.dashboard'), PHP_URL_PATH), '/');
    }

    /**
     * An icon's URL with its file's time as the version.
     *
     * The service worker keeps versioned files and only those, so a redrawn
     * icon is a new URL rather than yesterday's picture served from a cache.
     * Root-relative, like every URL the app hands the browser here: the panel
     * is opened on hosts other than `APP_URL` (a LAN address, a second
     * domain), and an absolute URL on the wrong one is a cross-origin request
     * the panel's CSP refuses.
     */
    public static function icon(string $file): string
    {
        $path = public_path(self::ICON_DIR . '/' . $file);

        return parse_url(asset(self::ICON_DIR . '/' . $file), PHP_URL_PATH)
            . '?v=' . (is_file($path) ? filemtime($path) : 0);
    }

    /** A route's URL without its scheme and host, keeping the query. */
    public static function path(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * The status bar's colour for a page: the navy band on the sign-in
     * screens, the white top bar everywhere else.
     *
     * @param  array<int, string>  $scopes  the render hook's scopes, page classes among them
     */
    public static function themeColor(array $scopes): string
    {
        foreach ($scopes as $scope) {
            if (is_a($scope, SimplePage::class, true)) {
                return self::NAVY;
            }
        }

        return self::TOPBAR;
    }

    /**
     * What a phone's cached files belong to: this build.
     *
     * The Vite manifest changes with every `npm run build`, the panel theme
     * with every Tailwind run, Filament's own assets with its version, and the
     * worker's own code with this file. Any of them changing names a new cache,
     * and the worker deletes the old one when it takes over.
     */
    public static function version(string $workerSource): string
    {
        $parts = [$workerSource, (string) InstalledVersions::getVersion('filament/filament')];

        foreach (['build/manifest.json', 'css/filament/app/theme.css'] as $file) {
            $path = public_path($file);
            $parts[] = is_file($path) ? (string) filemtime($path) . ':' . (string) filesize($path) : '';
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 12);
    }
}
