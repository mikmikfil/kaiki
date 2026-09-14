<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Kaiki's own logo and colours — the platform's, not an operator's.
 *
 * ## Not a BrandProfile
 *
 * {@see BrandProfile} is per tenant, has a `tenant_id`, and carries everything
 * an operator's public pages need: fonts, radii, social links, widget theme,
 * custom CSS. None of that applies here. The platform panel is chrome around
 * somebody else's product, and the only two questions it has to answer are
 * "whose logo is in the corner" and "what colour are the buttons".
 *
 * Sharing the table would have meant a nullable `tenant_id` — and then every
 * tenant-scoped query in the product would have had a row it must remember to
 * exclude. That is the kind of exception that holds until somebody writes the
 * one query that forgets it.
 *
 * ## One row
 *
 * Enforced by a unique index on `singleton`, not by convention. {@see current()}
 * is the only way the application reads it, so there is one definition of what
 * "the platform brand" means and no `first()` scattered through the panels.
 *
 * @property int $id
 * @property string|null $logo_light_path
 * @property string|null $logo_dark_path
 * @property string|null $favicon_path
 * @property string $primary_color
 * @property string $accent_color
 */
class PlatformBrand extends Model
{
    protected $table = 'platform_brand';

    /**
     * `singleton` is deliberately absent: it is the constraint, not a field
     * anybody sets. The migration writes the only row there will ever be.
     *
     * @var list<string>
     */
    protected $fillable = [
        'logo_light_path',
        'logo_dark_path',
        'favicon_path',
        'primary_color',
        'accent_color',
    ];

    /**
     * The platform brand.
     *
     * The row is created by the migration, so in a migrated database this
     * always finds one. `firstOrCreate` rather than `firstOrFail` for the two
     * cases where it might not: a test that truncates, and an installation
     * whose migration predates this table being backfilled. Returning defaults
     * is better than a 500 on the login screen of every panel.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['singleton' => 1],
            [
                'primary_color' => (string) config('kaiki.branding.defaults.colors.primary', '#123A5E'),
                'accent_color' => (string) config('kaiki.branding.defaults.colors.accent', '#B5511F'),
            ],
        );
    }

    /**
     * The brand, or null when there is no database to ask.
     *
     * The panels read this while they render, and they also boot during
     * `artisan migrate` on an empty database, during `config:cache`, and in a
     * container whose database is not up yet. Any of those would otherwise turn
     * a missing table into a fatal error on a command that has nothing to do
     * with branding — so the question "what colour is the platform" is allowed
     * to answer "I don't know", and every caller falls back to the defaults it
     * would have used anyway.
     */
    public static function currentOrNull(): ?self
    {
        try {
            if (! Schema::hasTable('platform_brand')) {
                return null;
            }

            return static::current();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The logo for a theme, as a URL, or null to fall back to the brand name.
     *
     * Dark falls back to the light logo rather than to nothing: a platform that
     * uploaded one image meant it to be the logo, and a dark panel with no
     * wordmark at all looks broken in a way a slightly-wrong-contrast one does
     * not.
     */
    public static function logoUrl(bool $dark = false): ?string
    {
        $brand = self::currentOrNull();

        if (! $brand instanceof self) {
            return null;
        }

        $path = $dark
            ? ($brand->logo_dark_path ?? $brand->logo_light_path)
            : $brand->logo_light_path;

        return self::assetUrl($path);
    }

    public static function faviconUrl(): ?string
    {
        return self::assetUrl(self::currentOrNull()?->favicon_path);
    }

    /** The primary colour, falling back to the palette every operator starts from. */
    public static function primary(): string
    {
        $brand = self::currentOrNull();

        return $brand instanceof self
            ? $brand->primary_color
            : (string) config('kaiki.branding.defaults.colors.primary', '#123A5E');
    }

    public static function accent(): string
    {
        $brand = self::currentOrNull();

        return $brand instanceof self
            ? $brand->accent_color
            : (string) config('kaiki.branding.defaults.colors.accent', '#B5511F');
    }

    /** Same disk and same shape as an operator's own assets (ADR-0021). */
    private static function assetUrl(?string $path): ?string
    {
        return is_string($path) && $path !== ''
            ? Storage::disk((string) config('kaiki.branding.uploads.disk', 'public'))->url($path)
            : null;
    }
}
