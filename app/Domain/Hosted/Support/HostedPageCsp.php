<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Models\BrandProfile;

/**
 * The Content-Security-Policy a hosted page is served with (HOS-8, SEC-10).
 *
 * > Hosted pages carry a Content-Security-Policy allowing the platform API
 * > origin, the widget origin, Google Fonts **when configured**, and the
 * > gateway redirect origins, and nothing else.
 *
 * ## Built from the operator's own configuration, never from a constant
 *
 * The three clauses that vary are the point. A policy hard-coded with every
 * origin the platform might ever use is a policy that permits Google Fonts on
 * an operator who never chose a font, and a gateway the operator has not
 * connected — which is a wider hole than necessary on every tenant, forever.
 *
 * `fonts.gstatic.com` in particular is the one to get right: WGT-10 says the
 * operator font is loaded *only when configured*, and a policy that always
 * allowed it would make that requirement decorative. `HostedPageHeadersTest`
 * asserts its **absence** for an operator with no font, which is the only way
 * to test a rule about permissiveness — a page renders identically either way.
 *
 * ## No `unsafe-inline`, anywhere
 *
 * WGT-22 requires the widget to work under a strict policy, and a hosted page
 * that relaxed its own would make that untestable in the one place the platform
 * controls. The operator's brand colours reach the page as CSS custom
 * properties on a `<style>` element carrying a per-response **nonce**, which is
 * how a dynamic value gets in without opening the door to an injected one.
 *
 * ## Custom CSS is sanitised and still cannot become a script
 *
 * BRD-2 sanitises what an operator writes. This is the second line: even if a
 * sanitiser were bypassed, `script-src` does not admit inline script, so the
 * result is broken styling rather than a stored cross-site script on a shared
 * domain.
 */
final class HostedPageCsp
{
    /**
     * @param  list<string>  $gatewayOrigins  the redirect hosts of the gateways this operator has connected
     * @param  string  $nonce  the per-response nonce for the brand `<style>` block
     */
    public static function build(?BrandProfile $profile, array $gatewayOrigins, string $nonce): string
    {
        $self = "'self'";

        $directives = [
            'default-src' => [$self],

            // The widget is served from the platform's own origin (ADR-0011's
            // versioned path plus alias), so `self` covers it. It is named
            // explicitly anyway when the platform is configured to serve the
            // bundle from somewhere else.
            'script-src' => array_values(array_unique(array_filter([
                $self,
                self::origin(config('kaiki.hosted.widget_origin')),
            ]))),

            // The nonce is what lets the brand colours through without
            // `unsafe-inline`.
            'style-src' => array_values(array_filter([
                $self,
                "'nonce-{$nonce}'",
                self::fontStylesheetOrigin($profile),
            ])),

            'font-src' => array_values(array_filter([
                $self,
                self::fontFileOrigin($profile),
            ])),

            // Operator logos and trip photos are on the platform's own disk;
            // `data:` covers the inline SVG a QR or an icon uses.
            'img-src' => [$self, 'data:'],

            // The widget talks to the API. Nothing else on this page makes a
            // request at all.
            'connect-src' => array_values(array_unique(array_filter([
                $self,
                self::origin(config('kaiki.hosted.api_origin')),
            ]))),

            // Where a guest is sent to pay. Only the gateways this operator has
            // actually connected — an unconnected gateway is an origin nobody
            // on this page can reach.
            'form-action' => array_values(array_unique(array_merge([$self], self::originsOf($gatewayOrigins)))),

            // A hosted page is never framed, and never frames anything.
            'frame-ancestors' => ["'none'"],
            'frame-src' => ["'none'"],
            'object-src' => ["'none'"],
            'base-uri' => [$self],
        ];

        $parts = [];

        foreach ($directives as $name => $values) {
            $parts[] = $name . ' ' . implode(' ', $values);
        }

        return implode('; ', $parts);
    }

    /** A fresh nonce per response. Reusing one across responses is not a nonce. */
    public static function nonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * Google's stylesheet host, and only when the operator picked a Google font.
     *
     * BRD-1 lets an operator choose a curated family or name a Google font;
     * `FontSource` records which. A system stack issues no third-party request
     * at all (WGT-10), so the policy must not name one either.
     */
    private static function fontStylesheetOrigin(?BrandProfile $profile): ?string
    {
        return self::usesGoogleFont($profile) ? 'https://fonts.googleapis.com' : null;
    }

    private static function fontFileOrigin(?BrandProfile $profile): ?string
    {
        return self::usesGoogleFont($profile) ? 'https://fonts.gstatic.com' : null;
    }

    private static function usesGoogleFont(?BrandProfile $profile): bool
    {
        // `requiresExternalRequest()` rather than a comparison with the
        // `Google` case: the enum already answers exactly this question, and
        // a second source added later (a self-hosted family, say) would
        // otherwise have to be remembered here too.
        return $profile !== null && $profile->font_source->requiresExternalRequest();
    }

    /**
     * The caller's hosts, reduced to origins and with the unusable ones dropped.
     *
     * **The caller decides which gateways**, because "which providers has this
     * operator connected" is a question about credential rows and this class
     * has no business asking it — a support class that reached for the database
     * would be one nobody could test without one.
     *
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private static function originsOf(array $hosts): array
    {
        $origins = [];

        foreach ($hosts as $host) {
            $origin = self::origin($host);

            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    /** A configured value reduced to a scheme and host, or null when it is neither. */
    private static function origin(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return sprintf('%s://%s%s', $parts['scheme'], $parts['host'], $port);
    }
}
