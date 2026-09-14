<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

use App\Domain\Analytics\Actions\CountAnalyticsEvent;
use App\Http\Middleware\HostedPageHeaders;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One count per hosted page served (ADR-0032).
 *
 * ## Counted on the server, which is the whole point
 *
 * The alternative is a script on the page, and a script is three problems: it
 * needs something written to the visitor's browser to be useful, it is the
 * first thing a blocker removes, and it would be the only JavaScript on a set
 * of pages HOS-4 promises work without any. Counting a request we have already
 * served costs one statement and asks the visitor for nothing at all.
 *
 * ## Called from {@see HostedPageHeaders}, not from a middleware of its own
 *
 * A terminable middleware would be tidier and would count after the response
 * had gone — and it would miss the custom-domain root, which is served through
 * a **manual** pipeline in `HostedRootPipeline` where `terminate()` is never
 * called. One count missing on one URL shape is the kind of wrong that is
 * discovered months later by a number that is slightly too small. So this hangs
 * off the one class that is already on every hosted page by definition.
 *
 * ## What is not counted
 *
 * Anything that is not a plain successful `GET`: a form post, a redirect, a
 * 404, a conditional request answered `304`. And an obvious crawler, by user
 * agent — a blunt instrument, deliberately. A count that includes some robots
 * is honest about what it is; a filter nobody can audit is not, so this one is
 * nine words long and lives where it can be read.
 */
final class HostedPageViews
{
    /**
     * The nine words, matched case-insensitively against the user agent.
     *
     * @var list<string>
     */
    private const ROBOTS = ['bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'preview', 'monitor', 'headless', 'curl'];

    public static function record(Request $request, Response $response, Tenant $tenant): void
    {
        if (! self::countable($request, $response)) {
            return;
        }

        app(CountAnalyticsEvent::class)($tenant, AnalyticsMetric::PageView);
    }

    private static function countable(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }

        $agent = strtolower((string) $request->userAgent());

        foreach (self::ROBOTS as $robot) {
            if (str_contains($agent, $robot)) {
                return false;
            }
        }

        return true;
    }
}
