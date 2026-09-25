<?php

declare(strict_types=1);

namespace App\Providers\Filament;

/**
 * The stylesheet both panels load (2026-09-23).
 *
 * Kaiki's own panel theme when it has been built — Filament's CSS plus every
 * utility our views use, see `resources/css/filament/app/theme.css` — and
 * Filament's published stylesheet when it has not. A fresh checkout that has
 * not run `npm run build`, and CI, which never does, get a panel that looks as
 * it did before the theme existed rather than one with no stylesheet at all.
 *
 * Built by the Tailwind v3 CLI rather than Vite (the project is on v4,
 * Filament 3 is on v3), so there is no manifest to hash the file name; the
 * modification time is the version instead, and a rebuild reaches every
 * browser on its next page load.
 */
final class PanelTheme
{
    private const BUILT = 'css/filament/app/theme.css';

    private const FILAMENT = 'css/filament/filament/app.css';

    public static function url(): string
    {
        $built = public_path(self::BUILT);

        if (is_file($built)) {
            return asset(self::BUILT) . '?v=' . filemtime($built);
        }

        return asset(self::FILAMENT);
    }
}
