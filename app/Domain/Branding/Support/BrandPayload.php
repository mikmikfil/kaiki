<?php

declare(strict_types=1);

namespace App\Domain\Branding\Support;

use App\Enums\BrandAsset;
use App\Models\BrandProfile;

/**
 * What each audience is allowed to see of a brand (spec BRD-2, BRD-6, HOS-8).
 *
 * ## Why the widget's payload is built here and not at the endpoint
 *
 * BRD-2 is a **fixed** requirement: *"Custom CSS is sanitised and applies to
 * the hosted page only; it is never injected into the widget."* The widget runs
 * inside somebody else's page — a WordPress site, a Wix page, a hotel's
 * booking portal — and injecting an operator's stylesheet into it means the
 * operator can style, cover or restyle a third party's page from our script
 * tag. That is not a branding feature; it is a cross-site defacement primitive
 * that we would be shipping on purpose.
 *
 * A rule of that shape cannot live in the controller that happens to serve it
 * today. `GET /api/v1/branding` (#35) is the first widget-facing consumer;
 * M3's embed bootstrap and the WordPress plugin's REST proxy are the next two,
 * and each is a separate opportunity to forget. So the two payloads are built
 * in one place, `custom_css` appears in exactly one of them, and the test that
 * says so does not care which endpoint is asking.
 *
 * ## `internal` is not in either
 *
 * Neither payload carries `id`, `tenant_id` or timestamps (CNV-9: internal ids
 * are never exposed). There is no `uuid` here to expose instead, because
 * nothing addresses a brand profile — it is reached through its tenant.
 */
final class BrandPayload
{
    /**
     * Everything a widget on a third-party page may know.
     *
     * @return array<string, mixed>
     */
    public static function forWidget(BrandProfile $profile): array
    {
        return [
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
                // Stated rather than inferred from `source`, so the widget does
                // not re-derive a GDPR decision from a string it was handed.
                'external_request' => $profile->font_source->requiresExternalRequest(),
            ],
            'button_radius_px' => $profile->button_radius_px,
            'theme' => $profile->widget_theme->value,
            'logos' => [
                'light' => $profile->logo_light_path,
                'dark' => $profile->logo_dark_path,
            ],
            // `custom_css` is absent, and this comment is the second half of
            // BRD-2. Do not add it here "just for the hosted page" — the hosted
            // page has its own payload, one method down.
        ];
    }

    /**
     * The widget's payload plus what only a page we serve ourselves may have.
     *
     * The hosted page is on the operator's own domain or ours, rendered by us,
     * with the HOS-8 Content-Security-Policy over it — which is the context
     * that makes operator CSS a styling feature rather than an injection into
     * a stranger's site.
     *
     * @return array<string, mixed>
     */
    public static function forHostedPage(BrandProfile $profile): array
    {
        return [
            ...self::forWidget($profile),
            // Read through the model's accessor, so it is sanitised on the way
            // out even if it was written before the sanitiser was tightened
            // (§2.2).
            'custom_css' => $profile->custom_css,
            'favicon' => $profile->favicon_path,
            'email_footer_text' => $profile->email_footer_text,
            'social_links' => $profile->social_links,
        ];
    }

    /**
     * The asset slots a widget-facing payload may name.
     *
     * The favicon and the email header are not among them: one belongs to a
     * page we render and the other to an email we send, and neither is
     * something a third-party page has any use for.
     *
     * @return list<BrandAsset>
     */
    public static function widgetAssets(): array
    {
        return [BrandAsset::LogoLight, BrandAsset::LogoDark];
    }
}
