<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Http\Controllers\WidgetBundleController;

/**
 * Where a hosted page loads the booking widget from (ADR-0011, HOS-4).
 *
 * ## The alias, not a versioned path
 *
 * `/widget/kaiki-widget.js` rather than `/widget/v1.4.2/…`. A hosted page is
 * served by the platform that releases the widget, so pinning it to a version
 * would mean every operator's own site got a fix on release day and the pages
 * the platform itself serves did not — the exact inversion of who should be
 * ahead. The alias is cached for five minutes and revalidated, which is what
 * {@see WidgetBundleController} exists to arrange.
 *
 * ## The origin is the one the policy already names
 *
 * `KAIKI_HOSTED_WIDGET_ORIGIN` when it is set, the application's own URL
 * otherwise — read from the same config key
 * {@see HostedPageCsp} puts in `script-src`. One
 * source for both, because a page whose policy and whose markup disagree about
 * where the bundle lives fails as a silently blocked script: no error anybody
 * here can see, and a guest looking at the "email us" fallback.
 */
final class HostedWidget
{
    public static function bundleUrl(): string
    {
        $origin = config('kaiki.hosted.widget_origin');

        if (is_string($origin) && $origin !== '') {
            return rtrim($origin, '/') . '/widget/kaiki-widget.js';
        }

        return url('/widget/kaiki-widget.js');
    }
}
