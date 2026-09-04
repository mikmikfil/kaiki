<?php

declare(strict_types=1);

namespace App\Domain\Branding\Actions;

use App\Enums\BrandAsset;
use App\Enums\FontSource;
use App\Models\BrandProfile;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The brand, shaped for the widget (spec BRD-2, BRD-6, BRD-8, WGT-9).
 *
 * ## `custom_css` is never in this payload
 *
 * BRD-2 and the contract agree, and the reason is worth stating where somebody
 * might be tempted to add it: *"injecting operator CSS into someone else's site
 * is not ours to do."* The hosted page and verified custom domains get it in
 * M3, from a server-side context — never from a client-supplied parameter,
 * because a third-party embed that could ask for it would simply ask.
 *
 * The key is always null here rather than absent: the contract lists it, and a
 * widget that has to branch on presence rather than on value is a widget that
 * breaks when the hosted page starts sending one.
 *
 * ## Cached per tenant, through `Cache`
 *
 * ENV-7: never `Redis::` — local development has no Redis, and a facade that
 * only works on one machine is a facade that fails on the other. The key
 * carries the tenant id because a shared key is a cross-tenant read with extra
 * steps, and the locale because `email_footer_text` is translated.
 *
 * BRD-8 is what the TTL buys: an operator changes a colour in the panel and the
 * widget picks it up **with no rebuild**, within the cache window.
 */
final class GetBrandPayload
{
    /** @return array<string, mixed> */
    public function __invoke(Tenant $tenant, string $locale, bool $isTest = false): array
    {
        $payload = Cache::remember(
            self::cacheKey($tenant, $locale),
            (int) config('kaiki.branding.cache_ttl_seconds', 60),
            fn (): array => $this->build($tenant, $locale),
        );

        // Outside the cache entry: whether a request is a test one is a
        // property of the **key** that made it, not of the tenant, so caching
        // it would serve a live widget the sandbox flag.
        $payload['is_test'] = $isTest || $tenant->is_sandbox;

        return $payload;
    }

    /** Drop a tenant's cached payload — used by the panel on save (BRD-8). */
    public static function forget(Tenant $tenant): void
    {
        foreach (['el', 'en'] as $locale) {
            Cache::forget(self::cacheKey($tenant, $locale));
        }
    }

    public static function cacheKey(Tenant $tenant, string $locale): string
    {
        return "branding:{$tenant->getKey()}:{$locale}";
    }

    /** @return array<string, mixed> */
    private function build(Tenant $tenant, string $locale): array
    {
        /** @var BrandProfile $profile */
        $profile = BrandProfile::query()->firstOrFail();

        return [
            'tenant' => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
                'default_locale' => $tenant->default_locale,
                'currency' => $tenant->currency,
                'hosted_page_url' => null,
                'support_email' => $tenant->email,
                'support_phone' => null,
            ],
            'logo' => [
                'light_url' => $this->assetUrl($profile, BrandAsset::LogoLight),
                'dark_url' => $this->assetUrl($profile, BrandAsset::LogoDark),
                'favicon_url' => $this->assetUrl($profile, BrandAsset::Favicon),
            ],
            'colors' => [
                'primary' => $profile->color_primary,
                'secondary' => $profile->color_secondary,
                'accent' => $profile->color_accent,
                'background' => $profile->color_background,
                'text' => $profile->color_text,
            ],
            'font' => [
                'family' => $profile->font_family,
                'source' => $profile->font_source->value,
                'css_url' => $this->fontCssUrl($profile),
            ],
            'button_radius_px' => $profile->button_radius_px,
            'widget_theme' => $profile->widget_theme->value,
            'css_variables' => $this->cssVariables($profile),
            'social_links' => $profile->social_links,
            'email_footer_text' => $profile->email_footer_text,
            // BRD-2. See the class docblock — null, never absent.
            'custom_css' => null,
            'terms_url' => null,
            'privacy_url' => null,
        ];
    }

    /**
     * The custom properties the widget writes onto its Shadow DOM root.
     *
     * Built here rather than in the widget so that a colour is decided in one
     * place. WGT-9's promise is that the widget *"never hardcodes a colour"*,
     * and the way to keep that true is to give it no colour it did not read
     * from this map.
     *
     * @return array<string, string>
     */
    private function cssVariables(BrandProfile $profile): array
    {
        return [
            '--kaiki-primary' => $profile->color_primary,
            '--kaiki-secondary' => $profile->color_secondary,
            '--kaiki-accent' => $profile->color_accent,
            '--kaiki-background' => $profile->color_background,
            '--kaiki-text' => $profile->color_text,
            '--kaiki-radius' => "{$profile->button_radius_px}px",
            '--kaiki-font-family' => $profile->font_family,
        ];
    }

    /** A Google Fonts stylesheet, and only when the source says so. */
    private function fontCssUrl(BrandProfile $profile): ?string
    {
        if ($profile->font_source !== FontSource::Google) {
            return null;
        }

        return 'https://fonts.googleapis.com/css2?family='
            . rawurlencode($profile->font_family)
            . ':wght@400;500;600;700&display=swap';
    }

    private function assetUrl(BrandProfile $profile, BrandAsset $asset): ?string
    {
        $path = $profile->getAttribute($asset->column());

        return is_string($path) && $path !== ''
            ? Storage::disk(config('kaiki.branding.uploads.disk', 'public'))->url($path)
            : null;
    }
}
