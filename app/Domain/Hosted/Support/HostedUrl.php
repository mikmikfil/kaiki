<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Http\Controllers\Hosted\HostedPageController;
use App\Models\Product;
use App\Models\Tenant;

/**
 * Absolute URLs of an operator's hosted pages (HOS-1).
 *
 * ## Not `route()`, and that is the reason this class exists
 *
 * The hosted routes are registered on `book.{platform-domain}`
 * ({@see HostedPageController} for why the domain
 * is the guard). `route()` called from anywhere else — the panel, an API
 * response, a queued job building a link for an email — resolves against the
 * *current* request's host and produces a URL on `/app`'s domain or, in a job,
 * on `APP_URL`. Both are links that 404 for the person who clicks them.
 *
 * The panel's home-page screen learnt this first and built its "view your page"
 * link by hand; #104 needed the same URL in three more places — `booking_url`
 * and `canonical_url` in the API payloads, and the page's own canonical and
 * `hreflang` alternates — which is the point at which one hand-built string
 * becomes four that can disagree.
 *
 * ## Custom domains are #109's, and they change what this returns
 *
 * HOS-7: when a custom domain is active the platform URL 301s to it, so these
 * URLs stay correct but stop being canonical. That switch belongs here when it
 * lands, which is the other half of why the callers do not build strings.
 */
final class HostedUrl
{
    /** The operator's landing page. */
    public static function operator(Tenant $tenant, ?string $locale = null): string
    {
        return self::withLocale(sprintf('%s/%s', self::origin(), $tenant->slug), $locale);
    }

    /** One trip's page. */
    public static function product(Tenant $tenant, Product $product, ?string $locale = null): string
    {
        return self::withLocale(
            sprintf('%s/%s/%s', self::origin(), $tenant->slug, $product->slug),
            $locale,
        );
    }

    /**
     * Does this operator serve hosted pages at all?
     *
     * HOS-6 makes the page a 404 when the switch is off, so a payload that
     * carried the URL anyway would be advertising a dead link. The API resources
     * ask this before emitting `booking_url` and `canonical_url`.
     */
    public static function enabledFor(Tenant $tenant): bool
    {
        return (bool) $tenant->hosted_page_enabled;
    }

    /**
     * The scheme and host hosted pages are served from.
     *
     * `https` unless the configured host is a local one — the test host is
     * `book.kaiki.test` on plain HTTP, and a payload asserting an `https` URL
     * that the local server cannot answer is a test that passes while the link
     * fails.
     */
    private static function origin(): string
    {
        $host = (string) config('kaiki.tenancy.hosted_host');
        $scheme = str_ends_with($host, '.test') || str_starts_with($host, 'localhost') ? 'http' : 'https';

        return sprintf('%s://%s', $scheme, $host);
    }

    /**
     * HOS-5's per-locale URL.
     *
     * `?lang=` rather than a path segment, which is the convention #101 settled
     * and #86's token pages already use — a guest moving between the two
     * surfaces does not meet two conventions. Null means the operator's own
     * default, which is the URL with no parameter at all.
     */
    private static function withLocale(string $url, ?string $locale): string
    {
        return $locale === null ? $url : sprintf('%s?lang=%s', $url, $locale);
    }
}
