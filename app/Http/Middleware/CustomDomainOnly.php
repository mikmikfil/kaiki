<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Resolvers\CustomDomainResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The guard that lets the hosted pages live at the **root** of a custom domain
 * without swallowing the application (HOS-3, HOS-7).
 *
 * ## Why this exists at all
 *
 * On `book.{platform-domain}` the operator's pages are `/{slug}`, `/{slug}/search`
 * and so on. On the operator's **own** domain there is no slug — they are the
 * site — so the same pages have to answer at `/`, `/search` and `/{product}`.
 *
 * A `/{product}` route at the root of *every* host is precisely the mistake #101
 * made and documented: it matches `/app`, `/admin`, `/up` and every probe route
 * a test declares. The domain constraint that saved it there cannot be used
 * here, because the whole point is that the hostname is not known in advance.
 *
 * So the guard is this middleware. The routes are registered last **and** refuse
 * every request whose host did not resolve through {@see CustomDomainResolver} —
 * which only answers for a hostname that is `verified` in `tenant_domains`. A
 * request to `/app` on the panel's host never reaches them; a request to `/app`
 * on a verified custom domain is a 404, which is correct, because the panel is
 * not served there.
 */
class CustomDomainOnly
{
    public function __construct(private readonly CustomDomainResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Asked again rather than read off the resolved tenant: `tenant` may
        // have resolved this request by API key or by panel session, and those
        // must not open the hosted pages at the root of the panel's host.
        if ($this->resolver->resolve($request) === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
