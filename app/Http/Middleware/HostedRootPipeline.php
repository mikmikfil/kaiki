<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Resolvers\CustomDomainResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * The middleware `/` cannot have unconditionally (#109).
 *
 * ## The problem this solves
 *
 * On an operator's own domain, `/` is their home page and needs the whole hosted
 * pipeline: `ResolveTenant`, {@see HostedPageHeaders}'s nonce and policy, and
 * `SetLocale`. On the platform's own host, `/` is the platform's front page and
 * needs none of it — and **`ResolveTenant` 404s when nothing resolves**, which
 * is right everywhere except here.
 *
 * The route cannot carry the middleware conditionally and cannot be registered
 * twice: Laravel keys routes by method + domain + URI, so a second `/` replaces
 * the first rather than competing with it. That is how #109 turned the
 * platform's root into a 404 with only the smoke test to notice.
 *
 * So the decision moves into the pipeline itself. A hostname that resolves
 * through {@see CustomDomainResolver} — which answers only for a **verified**
 * row — gets the hosted middleware stack; everything else passes straight
 * through to a controller that renders the platform's own page.
 *
 * ## Why not simply check the host against a list
 *
 * Because the list is a database table an operator writes to. "Is this a custom
 * domain" has exactly one correct answer and the resolver already owns it;
 * a second implementation here would be a second place to forget that only
 * `verified` counts.
 */
class HostedRootPipeline
{
    public function __construct(private readonly CustomDomainResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->resolver->resolve($request) === null) {
            return $next($request);
        }

        /** @var Response $response */
        $response = app(Pipeline::class)
            ->send($request)
            // The same three the hosted routes carry, by class rather than by
            // alias: a pipeline built here has no route to read aliases from.
            ->through([ResolveTenant::class, HostedPageHeaders::class, SetLocale::class])
            ->then(static fn (Request $piped): Response => $next($piped));

        return $response;
    }
}
