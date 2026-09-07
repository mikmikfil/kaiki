<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Domain\Tenancy\Resolvers\CustomDomainResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/` — the platform's own front page, or an operator's, depending on the host.
 *
 * ## Why one route decides rather than two routes competing
 *
 * Laravel's route collection is keyed by **method + domain + URI**, so two
 * routes for `/` with no domain constraint are not two routes: the second
 * silently replaces the first. #109 discovered that by registering a custom-
 * domain `/` and watching the platform's own root turn into a 404 — with the
 * smoke test as the only thing that noticed.
 *
 * A domain constraint cannot separate them either, because the whole point of a
 * custom domain is that the hostname is the operator's and unknown in advance.
 *
 * So `/` is one route that asks the question directly: did this hostname resolve
 * through {@see CustomDomainResolver}, which answers only for a **verified** row?
 * If it did, the operator's home page. If it did not, the platform's.
 *
 * The other three paths — `/legal`, `/search`, `/{product}` — collide with
 * nothing at the root and stay ordinary routes behind the `hosted.custom`
 * middleware.
 */
class RootController extends HostedController
{
    public function __invoke(Request $request, CustomDomainResolver $resolver, HostedPageController $hosted): Response
    {
        // Asked here rather than read off the resolved tenant: on the platform's
        // own host the `tenant` middleware may well have resolved a tenant by
        // panel session or API key, and neither of those means "serve this
        // operator's public home page at the root of the platform".
        if ($resolver->resolve($request) !== null) {
            return $hosted->index($request);
        }

        // Not a verified custom domain. The platform's own front page is served
        // on the platform's own hosts and **nowhere else**: a hostname somebody
        // pointed at us and never verified would otherwise show our marketing
        // page on their domain, which is an impersonation surface for free and
        // a duplicate of our own page in search.
        abort_unless($this->isPlatformHost($request), Response::HTTP_NOT_FOUND);

        return response(View::make('welcome')->render());
    }

    /**
     * The hosts the platform answers for as itself.
     *
     * `central_domains` is the tenancy package's own list and already holds the
     * application host; the hosted host is added because `book.…` is ours too.
     * Anything else arriving here is a stranger's DNS record.
     */
    protected function isPlatformHost(Request $request): bool
    {
        $host = strtolower($request->getHost());

        $ours = array_map(
            static fn (mixed $value): string => strtolower((string) parse_url((string) $value, PHP_URL_HOST) ?: (string) $value),
            [
                ...(array) config('tenancy.central_domains', []),
                config('kaiki.tenancy.hosted_host'),
                config('app.url'),
            ],
        );

        return in_array($host, array_filter($ours), true);
    }
}
