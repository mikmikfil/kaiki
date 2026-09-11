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
     * Where a guest finishes a booking (`/c/{manage_token}`).
     *
     * On the hosted origin rather than on whatever host built the draft, and
     * that is the whole reason it lives here: the widget runs on an operator's
     * own WordPress site, and a checkout link built from the current request
     * would point at their domain, where nothing serves it. Under #109's custom
     * domains this follows the operator's own, which is what makes the handoff
     * invisible to a guest.
     *
     * No locale suffix. The page reads the booking's own `locale` (TOK-5) and
     * accepts `?lang=` on top, exactly as the other token pages do.
     */
    public static function checkout(string $manageToken): string
    {
        return sprintf('%s/c/%s', self::origin(), $manageToken);
    }

    /**
     * Does this operator serve a page a guest can book a trip from?
     *
     * HOS-6 makes a page a 404 when it is not served, so a payload that carried
     * the URL anyway would be advertising a dead link. The API resources ask
     * this before emitting `booking_url` and `canonical_url`, both of which
     * point at a **product** page.
     *
     * ## Why this is not the same question as {@see self::homeEnabledFor()}
     *
     * It was, until ADR-0029. One `enabledFor()` answered for two callers that
     * have now diverged: the API points at a trip page, the panel's "view your
     * page" link points at the home page, and a *bookings only* operator serves
     * the first and not the second. A single method would have to be wrong for
     * one of them.
     */
    /**
     * Does this operator serve the marketing home page they compose from blocks?
     *
     * The only page a site mode can switch off. Trip pages, search and the legal
     * pages are served in both modes (ADR-0029 as amended 2026-09-11), so the
     * API's `booking_url` needs no such question.
     */
    public static function homeEnabledFor(Tenant $tenant): bool
    {
        return $tenant->hosted_site_mode->servesHomePage();
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
        $host = HostedHost::authority();

        // The authority may carry a port — `127.0.0.1:8001` is what a developer
        // running two servers sets — so the scheme is decided on the name.
        $name = HostedHost::name();

        $local = str_ends_with($name, '.test')
            || $name === 'localhost'
            || str_ends_with($name, '.localhost')
            || $name === '127.0.0.1'
            || $name === '::1';

        return sprintf('%s://%s', $local ? 'http' : 'https', $host);
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
