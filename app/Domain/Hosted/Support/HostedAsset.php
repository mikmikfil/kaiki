<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The URL a hosted page should use for an uploaded image.
 *
 * ## Why `Storage::url()` is the wrong call here
 *
 * It builds an absolute URL from `APP_URL`, which is the **panel's** origin. A
 * hosted page is served from `book.kaiki.gr` — or from an operator's own custom
 * domain — so every image on it would be a cross-origin request, and HOS-8's
 * Content-Security-Policy says `img-src 'self'`. The browser blocks it, the page
 * renders with holes in it, and nothing anywhere reports an error: a CSP
 * violation is logged in the visitor's console and nowhere else.
 *
 * A **root-relative** path resolves against whatever host is serving the page,
 * which is the same application on all of them. So it satisfies `'self'` on the
 * platform host, on a custom domain, and on a developer's second port, without
 * any of them needing to be named in a policy.
 *
 * Found by looking at a demo page whose hero was a solid colour where a
 * photograph should have been (issue 111).
 */
final class HostedAsset
{
    /** A root-relative URL for a path on the public disk. */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $absolute = Storage::disk('public')->url($path);

        // The path and anything after it, dropping whatever origin the disk
        // decided to prepend. A disk configured with a real CDN keeps its host,
        // because then the operator has deliberately put the images somewhere
        // else and the policy is theirs to widen.
        $host = parse_url($absolute, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($host === null || $host !== $appHost) {
            return $absolute;
        }

        return (string) preg_replace('#^https?://[^/]+#', '', $absolute);
    }
}
