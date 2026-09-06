<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Hosted\Support\HostedPageCsp;
use App\Domain\Tenancy\Resolvers\HostedSlugResolver;
use App\Models\BrandProfile;
use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a hosted page is served with (HOS-4, HOS-8, SEC-10).
 *
 * ## Resolution is not this class's job, and used to be
 *
 * The first version of this middleware looked the operator up by slug itself.
 * That was a **second** implementation of something #7 had already built and
 * built better: {@see HostedSlugResolver} is strategy 3 of TEN-4, it already
 * declines a tenant whose `hosted_page_enabled` is false so the chain ends in
 * HOS-6's plain 404, and it already caches the answer — which matters on a page
 * that is meant to be crawled.
 *
 * It also only reads the first path segment **on the hosted host**, which is
 * the guard the duplicate did not have: a catch-all `/{operator}` registered on
 * every host swallowed `/_probe`, `/app` and anything else a route might add,
 * and broke eight of #7's own tests the moment it landed. The routes are scoped
 * to that host now and the resolver does the resolving.
 *
 * ## Livewire must not inject itself here
 *
 * It will otherwise: it appends its script to every HTML response from the web
 * group, so a worker that has served a Filament page starts putting fifty
 * kilobytes of JavaScript — **and a CSRF token** — onto a public, crawlable,
 * cacheable page.
 *
 * Three things wrong with that, in order: a CSRF token in a response a CDN may
 * cache is a token handed to whoever gets the cached copy; HOS-4 requires the
 * page to work with no JavaScript and shipping some anyway invites a dependency
 * on it; and HOS-8's policy has no `unsafe-inline`, so it would be blocked in
 * the browser and present in the source, which is the worst of both.
 *
 * Found by `HostedPageLocaleTest`, which only failed when the whole file ran —
 * the injection needs Livewire to have booted earlier in the same process,
 * which is what production does and what a single test does not.
 *
 * ## The nonce is minted here, once
 *
 * The policy names it and the layout's brand `<style>` block carries it, so
 * both have to agree. Minting it here and putting it on the request is what
 * makes that structural rather than a convention two files remember.
 */
class HostedPageHeaders
{
    public const NONCE = 'hosted_csp_nonce';

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenancy::current();

        // The `tenant` middleware runs first and 404s when nothing resolves, so
        // this is a type narrowing rather than a second guard.
        abort_unless($tenant instanceof Tenant, Response::HTTP_NOT_FOUND);

        $nonce = HostedPageCsp::nonce();

        $request->attributes->set(self::NONCE, $nonce);

        config(['livewire.inject_assets' => false]);

        return $this->withHeaders($next($request), $tenant, $nonce);
    }

    /**
     * HOS-8's policy, plus the headers SEC-10 names for every guest surface.
     *
     * `X-Robots-Tag` is deliberately **absent**: unlike the token pages of #86,
     * a hosted page is exactly what an operator wants indexed. That difference
     * is the whole point of HOS-2's structured data.
     */
    private function withHeaders(Response $response, Tenant $tenant, string $nonce): Response
    {
        $profile = Tenancy::forTenant(
            $tenant,
            static fn (): ?BrandProfile => BrandProfile::query()->first(),
        );

        $response->headers->set(
            'Content-Security-Policy',
            HostedPageCsp::build($profile, $this->gatewayOrigins($tenant), $nonce),
        );

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }

    /**
     * The redirect hosts of the gateways this operator has actually connected.
     *
     * Not every gateway the platform supports. An operator on Viva alone has no
     * reason for a policy that admits Stripe, and the narrower the `form-action`
     * the less a stored-content bug could do with it.
     *
     * @return list<string>
     */
    private function gatewayOrigins(Tenant $tenant): array
    {
        /** @var array<string, string> $configured */
        $configured = config('kaiki.hosted.gateway_origins', []);

        return Tenancy::forTenant($tenant, static function () use ($configured): array {
            $connected = IntegrationCredential::query()
                ->where('is_active', true)
                ->pluck('provider')
                ->map(static fn ($provider): string => is_string($provider) ? $provider : $provider->value)
                ->all();

            $origins = [];

            foreach ($configured as $provider => $host) {
                if (in_array($provider, $connected, true)) {
                    $origins[] = $host;
                }
            }

            return $origins;
        });
    }
}
