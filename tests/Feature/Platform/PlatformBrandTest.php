<?php

declare(strict_types=1);

use App\Enums\BrandAsset;
use App\Models\PlatformBrand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Kaiki's own logo and colours.
 *
 * The three things worth a test are the ones that would fail somewhere far from
 * here: a second row (the panels would show whichever the database felt like),
 * a platform asset filed where an operator's are (a support export would hand
 * it over), and a missing table taking down `artisan` (branding would break a
 * command that has nothing to do with it).
 */

it('starts with the same palette every operator starts from', function (): void {
    $brand = PlatformBrand::current();

    expect($brand->primary_color)->toBe(config('kaiki.branding.defaults.colors.primary'))
        ->and($brand->accent_color)->toBe(config('kaiki.branding.defaults.colors.accent'));
});

it('has exactly one row, and the database refuses a second', function (): void {
    expect(PlatformBrand::query()->count())->toBe(1);

    // The unique index on `singleton`, not a convention in the application.
    // `QueryException` and not `Throwable`: a test that accepts any exception
    // passes just as happily when the insert fails for the wrong reason.
    expect(fn () => DB::table('platform_brand')->insert([
        'singleton' => 1,
        'primary_color' => '#000000',
        'accent_color' => '#FFFFFF',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(PlatformBrand::query()->count())->toBe(1);
});

it('returns the same row however many times it is asked', function (): void {
    expect(PlatformBrand::current()->id)->toBe(PlatformBrand::current()->id)
        ->and(PlatformBrand::query()->count())->toBe(1);
});

it('files platform assets outside every operator directory', function (): void {
    foreach (BrandAsset::cases() as $asset) {
        $platform = $asset->platformDirectory();

        expect($platform)->toStartWith('platform-brand/');

        // The reason the prefix exists: no tenant id, however chosen, can
        // produce this path from `directory()`.
        foreach ([0, 1, 42] as $tenantId) {
            expect($asset->directory($tenantId))->not->toBe($platform);
        }
    }
});

it('falls back to the defaults rather than failing when there is no table', function (): void {
    Schema::drop('platform_brand');

    // The panels call these while rendering, and they also boot during
    // `artisan migrate` on an empty database.
    expect(PlatformBrand::currentOrNull())->toBeNull()
        ->and(PlatformBrand::primary())->toBe(config('kaiki.branding.defaults.colors.primary'))
        ->and(PlatformBrand::accent())->toBe(config('kaiki.branding.defaults.colors.accent'))
        ->and(PlatformBrand::logoUrl())->toBeNull()
        ->and(PlatformBrand::faviconUrl())->toBeNull();
});

it('has no logo until one is uploaded, and says so with null', function (): void {
    // Null is what `brandLogo()` needs to fall back to the brand name; an empty
    // string would render an <img> with no source.
    expect(PlatformBrand::logoUrl())->toBeNull()
        ->and(PlatformBrand::logoUrl(dark: true))->toBeNull();
});

it('shows the light logo in dark mode when only one was uploaded', function (): void {
    PlatformBrand::current()->update(['logo_light_path' => 'platform-brand/logo_light/a.png']);

    expect(PlatformBrand::logoUrl(dark: true))->toBe(PlatformBrand::logoUrl());
});
